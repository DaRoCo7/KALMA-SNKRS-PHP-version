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

/**
 * Reglas de negocio de los pedidos: crear un pedido descontando el stock sin
 * vender dos veces la misma unidad, controlar los cambios de estado, reponer el
 * stock al cancelar y dejar cada operación en el historial y en la auditoría.
 *
 * La comprobación de las líneas (formato, precios, personalización) está en
 * LineasPedido. Este servicio recibe el usuario de la sesión como array, pero no
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
        $filtros = ['cliente_id' => $cliente['id']] + $this->filtros($query, admiteBusqueda: false);

        return $this->listado($filtros, $paginacion);
    }

    /** @throws HttpExcepcion 404 si no existe o es de otro cliente */
    public function verPropio(array $usuario, string $codigo): array
    {
        $cliente = $this->perfilCliente($usuario);
        return $this->detalle($this->buscar($codigo, $cliente['id']), vistaCliente: true);
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
        $this->transicionar($codigo, $cliente['id'], 'cancelado', $usuario, $comentario, null, $ip);

        return $this->detalle($this->pedidos->buscarPorCodigo($codigo), vistaCliente: true);
    }

    // =========================================================================
    // Piezas comunes
    // =========================================================================

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

                $this->pedidos->cambiarEstado($pedido['id'], $nuevo, $codigoSeguimiento);
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

    /** "Calle Mayor 3, 28013 Madrid, Madrid" o null si el perfil no tiene dirección */
    private function direccionDelPerfil(array $cliente): ?string
    {
        if ($cliente['direccion'] === null) {
            return null;
        }
        $localidad = trim(($cliente['codigo_postal'] ?? '') . ' ' . ($cliente['ciudad'] ?? ''));
        return implode(', ', array_filter([$cliente['direccion'], $localidad, $cliente['provincia']]));
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
        if (!preg_match('/^SF-\d{4}-\d{6,}$/', $codigo)) {
            throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
        }
    }

    /** Filtros de los listados: ?estado=pendiente (y ?buscar= en el área interna) */
    private function filtros(array $query, bool $admiteBusqueda): array
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
        if ($admiteBusqueda && $buscar !== '') {
            if (is_string($buscar) && mb_strlen(trim($buscar)) <= 60) {
                $filtros['buscar'] = trim($buscar);
            } else {
                $errores['buscar'] = 'Texto de hasta 60 caracteres';
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
                'id'           => (int) $p['cliente_id'],
                'nombre'       => trim("{$p['cliente_nombre']} {$p['cliente_apellidos']}"),
                'email'        => $p['cliente_email'],
                'tipo'         => $p['tipo_cliente'],
                'razon_social' => $p['razon_social'],
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
