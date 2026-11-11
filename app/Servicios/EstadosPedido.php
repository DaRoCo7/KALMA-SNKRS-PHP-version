<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\StockRepositorio;
use App\Seguridad\Permisos;

/**
 * Máquina de estados de los pedidos: qué cambios se permiten, quién puede
 * hacerlos y cómo se aplica uno (transacción, historial, reposición de stock y
 * auditoría).
 *
 * Existe aparte porque la usan el cliente (cancelar lo suyo), la gestión
 * interna (todos los cambios) y el panel (qué botones pintar). Al estar en un
 * solo sitio, la cancelación del cliente y la de administración son el mismo
 * código y la pantalla no puede contradecir a la API.
 */
final class EstadosPedido
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
     * GestionPedidoServicio exige ADEMÁS el permiso de esta tabla. Se comprueba en
     * el servicio y no en la ruta porque depende del CUERPO de la petición (el
     * estado al que se quiere pasar), y el middleware de permisos solo conoce la ruta.
     *
     * Destino => permiso extra. Un destino que no aparece no necesita nada más.
     * (La clienta cancela SUS pedidos por otra vía: PedidoClienteServicio.)
     */
    private const PERMISO_EXTRA_POR_DESTINO = [
        'confirmado' => 'pedidos.confirmar_cancelar',
        'cancelado'  => 'pedidos.confirmar_cancelar',
    ];

    public function __construct(
        private readonly PedidoRepositorio $pedidos = new PedidoRepositorio(),
        private readonly StockRepositorio $stock = new StockRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    // =========================================================================
    // Reglas (sin BD)
    // =========================================================================

    /**
     * Estados a los que se puede llevar un pedido: los que aparecen como destino
     * en TRANSICIONES. A «pendiente» no se vuelve nunca, así que es un 400.
     *
     * @return list<string>
     */
    public static function destinos(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TRANSICIONES))));
    }

    /**
     * ¿Puede este rol llevar un pedido a $estado desde el área interna?
     * Aplica la separación de funciones de PERMISO_EXTRA_POR_DESTINO: almacén
     * sí puede a preparación, enviado y entregado; a confirmado y cancelado, no.
     */
    public static function puedeLlevarA(string $rol, string $estado): bool
    {
        $permisoExtra = self::permisoExtra($estado);
        return $permisoExtra === null || Permisos::tiene($rol, $permisoExtra);
    }

    /** Permiso que exige un destino además de pedidos.cambiar_estado (null si ninguno) */
    public static function permisoExtra(string $estado): ?string
    {
        return self::PERMISO_EXTRA_POR_DESTINO[$estado] ?? null;
    }

    /**
     * Para cada estado, los estados a los que ESTE rol puede llevar un pedido
     * desde el área interna. El panel lo usa para pintar solo los botones que
     * funcionarán; sale de las mismas tablas que cambiarEstado(), así la
     * pantalla y la API no pueden contradecirse. La API sigue comprobándolo.
     *
     * @return array<string, list<string>>
     */
    public static function transicionesPara(string $rol): array
    {
        return array_map(
            static fn (array $destinos): array => array_values(array_filter(
                $destinos,
                static fn (string $destino): bool => self::puedeLlevarA($rol, $destino)
            )),
            self::TRANSICIONES
        );
    }

    /** ¿Se puede cancelar todavía un pedido en este estado? (lo pinta el detalle) */
    public static function sePuedeCancelar(string $estado): bool
    {
        return in_array('cancelado', self::TRANSICIONES[$estado], true);
    }

    // =========================================================================
    // Aplicar un cambio
    // =========================================================================

    /**
     * Cambia el estado de un pedido respetando TRANSICIONES. Si el nuevo estado
     * es "cancelado", repone el stock de todas las líneas.
     *
     * El pedido se bloquea (FOR UPDATE) antes de leer su estado: si llegan dos
     * cancelaciones a la vez, la segunda espera, lee "cancelado" y recibe 409.
     * Así el stock se repone una sola vez.
     *
     * Quién puede pedir el cambio NO se comprueba aquí: lo decide quien llama
     * (el cliente solo cancela lo suyo, la gestión mira el rol).
     *
     * @param int|null $clienteId si se indica, el pedido tiene que ser de ese cliente
     * @throws HttpExcepcion 404 no existe (o no es de ese cliente) · 409 transición no permitida o seguimiento repetido
     */
    public function transicionar(
        string $codigo,
        ?int $clienteId,
        string $nuevo,
        array $usuario,
        ?string $comentario,
        ?string $codigoSeguimiento,
        ?string $ip,
    ): void {
        LecturaPedidos::comprobarCodigo($codigo);
        $tipoEvento = $nuevo === 'cancelado' ? 'pedido.cancelar' : 'pedido.cambiar_estado';

        try {
            BaseDatos::transaccion(function () use ($codigo, $clienteId, $nuevo, $usuario, $comentario, $codigoSeguimiento, $ip, $tipoEvento): void {
                $pedido = $this->pedidos->buscarPorCodigo($codigo, $clienteId, bloquear: true)
                    ?? throw HttpExcepcion::noEncontrado(LecturaPedidos::NO_ENCONTRADO);
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
}
