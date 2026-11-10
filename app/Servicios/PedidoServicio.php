<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\ProductoRepositorio;
use App\Repositorios\StockRepositorio;
use App\Repositorios\UsuarioRepositorio;
use App\Seguridad\Permisos;

/**
 * Reglas de negocio de los pedidos: crear un pedido descontando el stock sin
 * vender dos veces la misma unidad, controlar los cambios de estado, reponer el
 * stock al cancelar y dejar cada operación en el historial y en la auditoría.
 *
 * La comprobación de las líneas (formato, existencia, personalización y precio)
 * está en LineasPedido. Este servicio recibe el usuario de la sesión como array, pero no
 * sabe nada de HTTP: eso es cosa de los controladores.
 */
final class PedidoServicio
{
    public const ESTADOS = ['pendiente', 'confirmado', 'preparacion', 'enviado', 'entregado', 'cancelado'];

    /**
     * Estado actual => estados a los que puede pasar. Cualquier otro cambio es
     * un 409. Solo se cancela antes de preparar el pedido: después, las zapatillas
     * ya están empaquetadas o en camino.
     */
    public const TRANSICIONES = [
        'pendiente'   => ['confirmado', 'cancelado'],
        'confirmado'  => ['preparacion', 'cancelado'],
        'preparacion' => ['enviado'],
        'enviado'     => ['entregado'],
        'entregado'   => [],
        'cancelado'   => [],
    ];

    /**
     * SEPARACIÓN DE FUNCIONES en el área interna (regla decidida para KALMA).
     *
     * El almacén lleva la LOGÍSTICA: mueve las cajas de confirmado a preparación,
     * enviado y entregado. Los dos cambios que tienen que ver con el DINERO los
     * decide administración:
     *   - confirmado: alguien ha comprobado que el pago ha llegado. Si el almacén
     *     pudiera confirmar, podría preparar y enviar un pedido sin cobrarlo.
     *   - cancelado:  anula la venta, repone el stock y obliga a devolver el pago.
     *
     * La ruta PATCH /api/gestion/pedidos/{codigo}/estado solo exige
     * pedidos.cambiar_estado (lo tienen almacén y admin). Para estos dos destinos
     * el servicio exige ADEMÁS el permiso de esta tabla. Se comprueba aquí y no en
     * la ruta porque depende del CUERPO de la petición (el estado al que se quiere
     * pasar), y el middleware de permisos solo conoce la ruta.
     *
     * Destino => permiso extra. Un destino que no aparece no necesita nada más.
     * (La clienta cancela SUS pedidos por otra vía: cancelarPropio.)
     */
    private const PERMISO_EXTRA_POR_DESTINO = [
        'confirmado' => 'pedidos.confirmar_cancelar',
        'cancelado'  => 'pedidos.confirmar_cancelar',
    ];

    /** Comentario del historial cuando el área interna no escribe uno */
    private const COMENTARIOS_GESTION = [
        'confirmado'  => 'Pago verificado',
        'preparacion' => 'En preparación en almacén',
        'enviado'     => 'Entregado al transportista',
        'entregado'   => 'Entregado al cliente',
        'cancelado'   => 'Cancelado por administración',
    ];

    /** Formato del código visible de un pedido: SF-2026-000025 */
    private const FORMATO_CODIGO = '/^SF-\d{4}-\d{6,}$/';

    private const NO_ENCONTRADO = 'Pedido no encontrado';
    private const DATOS_NO_VALIDOS = 'Hay datos no válidos';

    public function __construct(
        private readonly PedidoRepositorio $pedidos = new PedidoRepositorio(),
        private readonly StockRepositorio $stock = new StockRepositorio(),
        private readonly UsuarioRepositorio $usuarios = new UsuarioRepositorio(),
        private readonly ProductoRepositorio $productos = new ProductoRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly LineasPedido $lineasPedido = new LineasPedido(),
    ) {
    }

    // =========================================================================
    // Pedidos propios (cliente)
    // =========================================================================

    /**
     * Crea un pedido con varias líneas y descuenta el stock. O se hace entero o
     * no se hace nada.
     *
     * @throws HttpExcepcion 400 datos no válidos · 403 la cuenta no es de cliente ·
     *                       409 alguna talla no tiene stock suficiente
     */
    public function crear(array $usuario, array $entrada, ?string $ip): array
    {
        $cliente = $this->perfilCliente($usuario);

        // 1. Comprobar la forma de los datos sin tocar la BD, con todos los errores juntos
        $errores = [];
        $lineas = $this->lineasPedido->validar($entrada['lineas'] ?? null, $errores);
        $datos = [];
        try {
            $datos = (new Validador($entrada))
                ->texto('direccion_envio', 400, obligatorio: false)
                ->texto('notas', 500, obligatorio: false)
                ->validar();
        } catch (HttpExcepcion $error) {
            $errores += $error->detalles['errores'] ?? [];
        }
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, $errores);
        }

        $direccion = $datos['direccion_envio'] ?? $this->direccionDelPerfil($cliente)
            ?? throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, [
                'direccion_envio' => 'Obligatoria: tu perfil no tiene dirección',
            ]);
        $notas = $datos['notas'] ?? null;

        // Catálogo de opciones de personalización activas, indexado por id
        $opciones = array_column($this->productos->opcionesPersonalizacion(), null, 'id');

        // 2. Las escrituras van en una transacción: si algo falla no queda ni el
        //    pedido a medias ni stock descontado
        try {
            $nuevo = BaseDatos::transaccion(function () use ($usuario, $cliente, $lineas, $opciones, $direccion, $notas, $ip): array {
                // Bloquear las tallas ANTES de leer su stock (ver StockRepositorio)
                $variantes = $this->stock->bloquearVariantes(array_column($lineas, 'variante_id'));
                $preparadas = $this->lineasPedido->preparar($lineas, $variantes, $opciones);

                $personalizado = array_filter($preparadas, static fn (array $l): bool => $l['personalizacion'] !== []) !== [];
                $pedido = $this->pedidos->crear($cliente['id'], $direccion, $notas, $personalizado);

                foreach ($preparadas as $linea) {
                    $lineaId = $this->pedidos->agregarLinea($pedido['id'], $linea);
                    foreach ($linea['personalizacion'] as $opcion) {
                        $this->pedidos->agregarPersonalizacion($lineaId, $opcion['opcion_id'], $opcion['texto'], $opcion['recargo']);
                    }
                    $this->stock->mover(
                        $linea['variante_id'], -$linea['cantidad'], 'venta',
                        $pedido['id'], $usuario['id'], "Pedido {$pedido['codigo']}"
                    );
                }

                $total = $this->pedidos->actualizarTotal($pedido['id']);
                $this->pedidos->registrarEstado($pedido['id'], null, 'pendiente', $usuario['id'], 'Pedido creado por el cliente');
                $this->auditoria->registrar($usuario['id'], 'pedido.crear', 'pedido', $pedido['id'], 'exito', [
                    'codigo'   => $pedido['codigo'],
                    'lineas'   => count($preparadas),
                    'unidades' => array_sum(array_column($preparadas, 'cantidad')),
                    'total'    => $total,
                ], $ip);

                return $pedido;
            });
        } catch (HttpExcepcion $error) {
            if ($error->estado === 409) {
                // El intento fallido se audita FUERA de la transacción: dentro, el
                // ROLLBACK habría borrado también este registro
                $this->auditoria->registrar($usuario['id'], 'pedido.crear', 'pedido', null, 'error', [
                    'motivo'    => 'sin stock',
                    'sin_stock' => $error->detalles['sin_stock'] ?? [],
                ], $ip);
            }
            throw $error;
        }

        Log::info('Pedido creado', ['pedido_id' => $nuevo['id'], 'codigo' => $nuevo['codigo']]);
        return $this->detalle($this->pedidos->buscarPorCodigo($nuevo['codigo']), vistaCliente: true);
    }

    /** Pedidos del cliente que ha iniciado sesión (nunca los de otros) */
    public function listarPropios(array $usuario, array $query, Paginacion $paginacion): array
    {
        $cliente = $this->perfilCliente($usuario);
        $filtros = ['cliente_id' => $cliente['id']] + $this->filtros($query, areaInterna: false);

        return $this->listado($filtros, $paginacion);
    }

    /**
     * @param string|null $ip para la auditoría si el pedido es de otro cliente
     * @throws HttpExcepcion 404 si no existe o es de otro cliente
     */
    public function verPropio(array $usuario, string $codigo, ?string $ip = null): array
    {
        $cliente = $this->perfilCliente($usuario);
        try {
            $pedido = $this->buscar($codigo, $cliente['id']);
        } catch (HttpExcepcion $error) {
            $this->registrarAccesoAjeno($usuario, $codigo, 'pedido.ver', $ip);
            throw $error;
        }
        return $this->detalle($pedido, vistaCliente: true);
    }

    /**
     * El cliente cancela su pedido (solo desde pendiente o confirmado) y el
     * stock se repone.
     *
     * @throws HttpExcepcion 404 no existe o es de otro · 409 su estado ya no permite cancelar
     */
    public function cancelarPropio(array $usuario, string $codigo, array $entrada, ?string $ip): array
    {
        $cliente = $this->perfilCliente($usuario);
        $datos = (new Validador($entrada))->texto('motivo', 200, obligatorio: false)->validar();

        $comentario = 'Cancelado por el cliente' . (isset($datos['motivo']) ? ': ' . $datos['motivo'] : '');
        try {
            $this->transicionar($codigo, $cliente['id'], 'cancelado', $usuario, $comentario, null, $ip);
        } catch (HttpExcepcion $error) {
            if ($error->estado === 404) {
                $this->registrarAccesoAjeno($usuario, $codigo, 'pedido.cancelar', $ip);
            }
            throw $error;
        }

        return $this->detalle($this->pedidos->buscarPorCodigo($codigo), vistaCliente: true);
    }

    // =========================================================================
    // Área interna (almacén y administración)
    // =========================================================================

    /** Todos los pedidos: ?estado, ?buscar (código o email) y ?personalizado=1|0 */
    public function listarTodos(array $query, Paginacion $paginacion): array
    {
        return $this->listado($this->filtros($query, areaInterna: true), $paginacion);
    }

    /**
     * Detalle de cualquier pedido. El historial incluye el nombre de quien hizo
     * cada cambio: dentro de la empresa sí interesa saber quién fue.
     *
     * @throws HttpExcepcion 404
     */
    public function verInterno(string $codigo): array
    {
        return $this->detalle($this->buscar($codigo, null), vistaCliente: false);
    }

    /**
     * Cambia el estado de un pedido desde el área interna.
     * Cuerpo: {estado, comentario?, codigo_seguimiento? (obligatorio para «enviado»)}
     *
     * Orden de las comprobaciones: datos (400) → permiso según el destino (403,
     * ver PERMISO_EXTRA_POR_DESTINO) → existe (404) → transición permitida y
     * seguimiento libre (409). Cancelar repone el stock (lo hace transicionar).
     *
     * @throws HttpExcepcion 400 · 403 · 404 · 409
     */
    public function cambiarEstado(array $usuario, string $codigo, array $entrada, ?string $ip): array
    {
        // El seguimiento se guarda en mayúsculas: «ab-123» y «AB-123» son el mismo
        // envío y el UNIQUE de la columna tiene que verlos como iguales
        if (is_string($entrada['codigo_seguimiento'] ?? null)) {
            $entrada['codigo_seguimiento'] = mb_strtoupper(trim($entrada['codigo_seguimiento']));
        }
        $datos = (new Validador($entrada))
            ->opcion('estado', self::destinos())
            ->texto('comentario', 255, obligatorio: false)   // tamaño de historial_estados_pedido.comentario
            ->patron('codigo_seguimiento', '/^[A-Z0-9-]{1,50}$/', 'Hasta 50 caracteres: letras, números y guiones')
            ->validar();

        $nuevo = $datos['estado'];
        $seguimiento = $datos['codigo_seguimiento'] ?? null;

        // Sin número de envío el cliente no puede seguir su paquete: se pide justo al
        // entregarlo al transportista. En otro cambio no tiene sentido y se rechaza
        // para que nadie crea que lo ha guardado.
        if ($nuevo === 'enviado' && $seguimiento === null) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, [
                'codigo_seguimiento' => 'Obligatorio para pasar a «enviado»',
            ]);
        }
        if ($nuevo !== 'enviado' && $seguimiento !== null) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, [
                'codigo_seguimiento' => 'Solo se indica al pasar a «enviado»',
            ]);
        }

        if (!self::puedeLlevarA($usuario['rol'], $nuevo)) {
            $this->auditoria->registrar(
                $usuario['id'], 'pedido.cambiar_estado', 'pedido',
                $this->pedidos->buscarPorCodigo($codigo)['id'] ?? null, 'denegado',
                [
                    'codigo'  => $codigo,
                    'a'       => $nuevo,
                    'rol'     => $usuario['rol'],
                    'permiso' => self::PERMISO_EXTRA_POR_DESTINO[$nuevo],
                ],
                $ip
            );
            throw HttpExcepcion::prohibido('Confirmar el pago o cancelar un pedido es decisión de administración');
        }

        $comentario = $datos['comentario'] ?? self::COMENTARIOS_GESTION[$nuevo];
        $this->transicionar($codigo, null, $nuevo, $usuario, $comentario, $seguimiento, $ip);

        return $this->detalle($this->pedidos->buscarPorCodigo($codigo), vistaCliente: false);
    }

    /**
     * ¿Puede este rol llevar un pedido a $estado desde el área interna?
     * Aplica la separación de funciones de PERMISO_EXTRA_POR_DESTINO: almacén
     * sí puede a preparación, enviado y entregado; a confirmado y cancelado, no.
     */
    private static function puedeLlevarA(string $rol, string $estado): bool
    {
        $permisoExtra = self::PERMISO_EXTRA_POR_DESTINO[$estado] ?? null;
        return $permisoExtra === null || Permisos::tiene($rol, $permisoExtra);
    }

    /**
     * Estados a los que se puede llevar un pedido: los que aparecen como destino
     * en TRANSICIONES. A «pendiente» no se vuelve nunca, así que es un 400.
     *
     * @return list<string>
     */
    private static function destinos(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TRANSICIONES))));
    }

    // =========================================================================
    // Piezas comunes
    // =========================================================================

    /**
     * Protección IDOR con rastro.
     *
     * La clienta recibe el MISMO 404 si el código no existe que si es de otro
     * cliente: así no puede averiguar qué códigos existen probando. Pero si el
     * pedido SÍ existe, alguien está pidiendo pedidos ajenos (cambiando el código
     * de la URL) y administración debe poder verlo en la auditoría.
     */
    private function registrarAccesoAjeno(array $usuario, string $codigo, string $tipoEvento, ?string $ip): void
    {
        if (!preg_match(self::FORMATO_CODIGO, $codigo)) {
            return;   // con otro formato no puede existir: ni se consulta la BD
        }
        $ajeno = $this->pedidos->buscarPorCodigo($codigo);
        if ($ajeno !== null) {
            $this->auditoria->registrar($usuario['id'], $tipoEvento, 'pedido', (int) $ajeno['id'], 'denegado', [
                'codigo' => $codigo,
                'motivo' => 'el pedido pertenece a otro cliente',
            ], $ip);
        }
    }

    /**
     * Cambia el estado de un pedido respetando TRANSICIONES. Si el nuevo estado
     * es "cancelado", repone el stock de todas las líneas.
     *
     * El pedido se bloquea (FOR UPDATE) antes de leer su estado: si llegan dos
     * cancelaciones a la vez, la segunda espera, lee "cancelado" y recibe 409.
     * Así el stock se repone una sola vez.
     *
     * @param int|null $clienteId si se indica, el pedido tiene que ser de ese cliente
     */
    private function transicionar(
        string $codigo,
        ?int $clienteId,
        string $nuevo,
        array $usuario,
        ?string $comentario,
        ?string $codigoSeguimiento,
        ?string $ip,
    ): void {
        $this->comprobarCodigo($codigo);
        $tipoEvento = $nuevo === 'cancelado' ? 'pedido.cancelar' : 'pedido.cambiar_estado';

        try {
            BaseDatos::transaccion(function () use ($codigo, $clienteId, $nuevo, $usuario, $comentario, $codigoSeguimiento, $ip, $tipoEvento): void {
                $pedido = $this->pedidos->buscarPorCodigo($codigo, $clienteId, bloquear: true)
                    ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
                $anterior = $pedido['estado'];

                if (!in_array($nuevo, self::TRANSICIONES[$anterior], true)) {
                    throw HttpExcepcion::conflicto(
                        $nuevo === 'cancelado'
                            ? "Un pedido en estado «{$anterior}» ya no se puede cancelar"
                            : "Un pedido en estado «{$anterior}» no puede pasar a «{$nuevo}»",
                        ['estado_actual' => $anterior, 'estados_permitidos' => self::TRANSICIONES[$anterior]]
                    );
                }

                $this->guardarEstado((int) $pedido['id'], $nuevo, $codigoSeguimiento);
                $this->pedidos->registrarEstado($pedido['id'], $anterior, $nuevo, $usuario['id'], $comentario);

                $detalle = ['codigo' => $codigo, 'de' => $anterior, 'a' => $nuevo];
                if ($nuevo === 'cancelado') {
                    $detalle['unidades_repuestas'] = $this->reponerStock($pedido, $usuario['id']);
                }
                $this->auditoria->registrar($usuario['id'], $tipoEvento, 'pedido', $pedido['id'], 'exito', $detalle, $ip);
            });
        } catch (HttpExcepcion $error) {
            if ($error->estado === 409) {
                // Tras el ROLLBACK se vuelve a leer el id solo para que la auditoría diga de qué pedido se trata
                $pedidoId = $this->pedidos->buscarPorCodigo($codigo, $clienteId)['id'] ?? null;
                $this->auditoria->registrar($usuario['id'], $tipoEvento, 'pedido', $pedidoId, 'error', [
                    'codigo' => $codigo, 'a' => $nuevo, 'motivo' => $error->getMessage(),
                ], $ip);
            }
            throw $error;
        }
    }

    /**
     * Guarda el estado nuevo (y el seguimiento, si llega).
     *
     * 23505 = UNIQUE violado. En este UPDATE solo puede ser codigo_seguimiento:
     * dos pedidos no comparten el número de envío del transportista. Se confía en
     * la restricción de la BD y no en un SELECT previo, que no evitaría que dos
     * peticiones simultáneas guardaran el mismo código.
     *
     * @throws HttpExcepcion 409 si el seguimiento ya lo tiene otro pedido
     */
    private function guardarEstado(int $pedidoId, string $nuevo, ?string $codigoSeguimiento): void
    {
        try {
            $this->pedidos->cambiarEstado($pedidoId, $nuevo, $codigoSeguimiento);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23505') {
                throw HttpExcepcion::conflicto(
                    'Ese código de seguimiento ya lo tiene otro pedido',
                    ['codigo_seguimiento' => $codigoSeguimiento]
                );
            }
            throw $error;
        }
    }

    /** Devuelve al stock las unidades de cada línea. @return int unidades repuestas */
    private function reponerStock(array $pedido, int $usuarioId): int
    {
        $lineas = $this->pedidos->lineas($pedido['id']);
        // Mismo orden que al bloquear en un pedido nuevo (por id de talla): evita interbloqueos
        usort($lineas, static fn (array $a, array $b): int => $a['variante_id'] <=> $b['variante_id']);

        $unidades = 0;
        foreach ($lineas as $linea) {
            $this->stock->mover(
                (int) $linea['variante_id'], (int) $linea['cantidad'], 'cancelacion',
                $pedido['id'], $usuarioId, "Cancelación del pedido {$pedido['codigo']}"
            );
            $unidades += (int) $linea['cantidad'];
        }
        return $unidades;
    }

    /** @throws HttpExcepcion 403 si la cuenta no tiene perfil de cliente (admin, almacén) */
    private function perfilCliente(array $usuario): array
    {
        return $this->usuarios->perfilCliente($usuario['id'])
            ?? throw HttpExcepcion::prohibido('Solo las cuentas de cliente tienen pedidos propios');
    }

    /**
     * "Calle Mayor 12, 2º A, 28013 Madrid (Madrid)", el mismo formato que los
     * pedidos de la semilla, o null si el perfil no tiene dirección.
     */
    private function direccionDelPerfil(array $cliente): ?string
    {
        if ($cliente['direccion'] === null) {
            return null;
        }
        $localidad = trim(($cliente['codigo_postal'] ?? '') . ' ' . ($cliente['ciudad'] ?? ''));
        $direccion = implode(', ', array_filter([$cliente['direccion'], $localidad]));
        return $cliente['provincia'] === null ? $direccion : "{$direccion} ({$cliente['provincia']})";
    }

    /** @throws HttpExcepcion 404 */
    private function buscar(string $codigo, ?int $clienteId): array
    {
        $this->comprobarCodigo($codigo);
        return $this->pedidos->buscarPorCodigo($codigo, $clienteId) ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
    }

    /** Un código con otro formato no puede existir: 404 sin consultar la BD */
    private function comprobarCodigo(string $codigo): void
    {
        if (!preg_match(self::FORMATO_CODIGO, $codigo)) {
            throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
        }
    }

    /** Filtros de los listados: ?estado=pendiente (y ?buscar= y ?personalizado= en el área interna) */
    private function filtros(array $query, bool $areaInterna): array
    {
        $filtros = [];
        $errores = [];

        $estado = $query['estado'] ?? '';
        if ($estado !== '') {
            if (in_array($estado, self::ESTADOS, true)) {
                $filtros['estado'] = $estado;
            } else {
                $errores['estado'] = 'Valores: ' . implode(', ', self::ESTADOS);
            }
        }

        $buscar = $query['buscar'] ?? '';
        if ($areaInterna && $buscar !== '') {
            if (is_string($buscar) && mb_strlen(trim($buscar)) <= 60) {
                $filtros['buscar'] = trim($buscar);
            } else {
                $errores['buscar'] = 'Texto de hasta 60 caracteres';
            }
        }

        // ?personalizado=1 son los que el taller tiene que preparar a mano (bordado, caja...)
        $personalizado = $query['personalizado'] ?? '';
        if ($areaInterna && $personalizado !== '') {
            $valor = is_string($personalizado)
                ? filter_var($personalizado, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : null;
            if ($valor === null) {
                $errores['personalizado'] = 'Valores: 1 o 0';
            } else {
                $filtros['personalizado'] = $valor;
            }
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        return $filtros;
    }

    private function listado(array $filtros, Paginacion $paginacion): array
    {
        $total = $this->pedidos->contar($filtros);
        $filas = $this->pedidos->listar($filtros, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map(fn (array $p): array => $this->resumen($p) + [
                'lineas'   => (int) $p['num_lineas'],
                'unidades' => (int) $p['unidades'],
            ], $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /** Datos de un pedido en los listados */
    private function resumen(array $p): array
    {
        return [
            'id'             => (int) $p['id'],
            'codigo'         => $p['codigo'],
            'estado'         => $p['estado'],
            'personalizado'  => $p['personalizado'],
            'total'          => (float) $p['total'],
            'fecha_creacion' => self::fecha($p['fecha_creacion']),
            'cliente'        => [
                'id'     => (int) $p['cliente_id'],
                'nombre' => trim("{$p['cliente_nombre']} {$p['cliente_apellidos']}"),
                'email'  => $p['cliente_email'],
            ],
        ];
    }

    /**
     * Pedido completo: cabecera, líneas con su personalización e historial.
     * En la vista del cliente el historial dice qué rol hizo cada cambio, pero
     * no el nombre de la persona del almacén o de administración.
     */
    private function detalle(array $p, bool $vistaCliente): array
    {
        $lineas = array_map(static fn (array $l): array => [
            'variante_id'             => (int) $l['variante_id'],
            'producto'                => $l['nombre_producto'],
            'color'                   => $l['color'],
            'slug'                    => $l['slug'],
            'imagen'                  => '/' . $l['imagen'],
            'talla'                   => (float) $l['talla'],
            'cantidad'                => (int) $l['cantidad'],
            'precio_unitario'         => (float) $l['precio_unitario'],
            'recargo_personalizacion' => (float) $l['recargo_personalizacion'],
            'importe'                 => (float) $l['importe'],
            'personalizacion'         => json_decode($l['personalizacion'], true),
        ], $this->pedidos->lineas($p['id']));

        $historial = array_map(static fn (array $h): array => [
            'estado_anterior' => $h['estado_anterior'],
            'estado_nuevo'    => $h['estado_nuevo'],
            'fecha'           => self::fecha($h['fecha']),
            'comentario'      => $h['comentario'],
            'rol'             => $h['usuario_rol'],
        ] + ($vistaCliente ? [] : ['usuario' => $h['usuario_nombre']]), $this->pedidos->historial($p['id']));

        return $this->resumen($p) + [
            'direccion_envio'     => $p['direccion_envio'],
            'codigo_seguimiento'  => $p['codigo_seguimiento'],
            'notas'               => $p['notas'],
            'fecha_actualizacion' => self::fecha($p['fecha_actualizacion']),
            'se_puede_cancelar'   => in_array('cancelado', self::TRANSICIONES[$p['estado']], true),
            'lineas'              => $lineas,
            'historial'           => $historial,
        ];
    }

    /** Fecha de PostgreSQL (2026-11-08 19:15:00+01) en ISO 8601 (2026-11-08T19:15:00+01:00) */
    private static function fecha(?string $valor): ?string
    {
        return $valor === null ? null : (new \DateTimeImmutable($valor))->format(DATE_ATOM);
    }
}
