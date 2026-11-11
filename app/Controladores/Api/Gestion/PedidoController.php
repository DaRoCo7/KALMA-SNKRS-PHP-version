<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\GestionPedidoServicio;

/**
 * API de pedidos del área interna (almacén y administración). Los permisos de
 * cada ruta están en config/rutas/operaciones.php.
 *
 * A diferencia de /api/pedidos, aquí se ven los pedidos de todos los clientes.
 * Qué estados puede poner cada rol lo decide EstadosPedido (confirmar y
 * cancelar solo administración).
 */
final class PedidoController
{
    public function __construct(private readonly GestionPedidoServicio $pedidos = new GestionPedidoServicio())
    {
    }

    /** GET /api/gestion/pedidos?estado=&buscar=&personalizado=1&pagina=1 · todos, del más reciente al más antiguo */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion);
        return Respuesta::json($this->pedidos->listarTodos($peticion->query, $paginacion));
    }

    /** GET /api/gestion/pedidos/{codigo} · detalle con el historial y quién hizo cada cambio */
    public function ver(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->pedidos->verInterno((string) $peticion->parametro('codigo'))]);
    }

    /**
     * PATCH /api/gestion/pedidos/{codigo}/estado · {"estado", "comentario"?, "codigo_seguimiento"?}
     * Es un PATCH porque modifica un solo campo del pedido; el cambio queda en el historial.
     */
    public function cambiarEstado(Peticion $peticion): Respuesta
    {
        $pedido = $this->pedidos->cambiarEstado(
            Sesion::usuario(), (string) $peticion->parametro('codigo'), $peticion->json(), $peticion->ip
        );
        return Respuesta::json(['datos' => $pedido]);
    }
}
