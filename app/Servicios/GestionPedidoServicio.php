<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\PedidoRepositorio;

/**
 * Pedidos del área interna (/api/gestion/pedidos): almacén y administración
 * ven los de todos los clientes y los mueven de estado.
 *
 * Existe para lo que solo pasa dentro de la empresa: validar el cuerpo del
 * cambio de estado (con el seguimiento al enviar) y aplicar la separación de
 * funciones por rol antes de tocar nada. La transición en sí la hace
 * EstadosPedido, la misma que usa la cancelación del cliente.
 */
final class GestionPedidoServicio
{
    /** Comentario del historial cuando el área interna no escribe uno */
    private const COMENTARIOS_GESTION = [
        'confirmado'  => 'Pago verificado',
        'preparacion' => 'En preparación en almacén',
        'enviado'     => 'Entregado al transportista',
        'entregado'   => 'Entregado al cliente',
        'cancelado'   => 'Cancelado por administración',
    ];

    private const DATOS_NO_VALIDOS = 'Hay datos no válidos';

    public function __construct(
        private readonly PedidoRepositorio $pedidos = new PedidoRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly LecturaPedidos $lectura = new LecturaPedidos(),
        private readonly EstadosPedido $estados = new EstadosPedido(),
    ) {
    }

    /** Todos los pedidos: ?estado, ?buscar (código o email) y ?personalizado=1|0 */
    public function listarTodos(array $query, Paginacion $paginacion): array
    {
        return $this->lectura->listado($query, true, $paginacion);
    }

    /**
     * Detalle de cualquier pedido. El historial incluye el nombre de quien hizo
     * cada cambio: dentro de la empresa sí interesa saber quién fue.
     *
     * @throws HttpExcepcion 404
     */
    public function verInterno(string $codigo): array
    {
        return $this->lectura->detalle($this->lectura->buscar($codigo, null), vistaCliente: false);
    }

    /**
     * Cambia el estado de un pedido desde el área interna.
     * Cuerpo: {estado, comentario?, codigo_seguimiento? (obligatorio para «enviado»)}
     *
     * Orden de las comprobaciones: datos (400) → permiso según el destino (403,
     * ver EstadosPedido::puedeLlevarA) → existe (404) → transición permitida y
     * seguimiento libre (409). Cancelar repone el stock (lo hace EstadosPedido).
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
            ->opcion('estado', EstadosPedido::destinos())
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

        if (!EstadosPedido::puedeLlevarA($usuario['rol'], $nuevo)) {
            $this->auditoria->registrar(
                $usuario['id'], 'pedido.cambiar_estado', 'pedido',
                $this->pedidos->buscarPorCodigo($codigo)['id'] ?? null, 'denegado',
                [
                    'codigo'  => $codigo,
                    'a'       => $nuevo,
                    'rol'     => $usuario['rol'],
                    'permiso' => EstadosPedido::permisoExtra($nuevo),
                ],
                $ip
            );
            throw HttpExcepcion::prohibido('Confirmar el pago o cancelar un pedido es decisión de administración');
        }

        $comentario = $datos['comentario'] ?? self::COMENTARIOS_GESTION[$nuevo];
        $this->estados->transicionar($codigo, null, $nuevo, $usuario, $comentario, $seguimiento, $ip);

        return $this->lectura->detallePorCodigo($codigo, vistaCliente: false);
    }
}
