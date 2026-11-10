<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\PedidoServicio;

/**
 * API de los pedidos propios del cliente. Todas las rutas exigen sesión y el
 * permiso pedidos.propios (ver config/rutas.php).
 *
 * El cliente nunca indica de quién es el pedido: se toma siempre del usuario de
 * la sesión. Así, cambiar el código en la URL no da acceso a pedidos ajenos.
 */
final class PedidoController
{
    public function __construct(private readonly PedidoServicio $pedidos = new PedidoServicio())
    {
    }

    /** GET /api/pedidos?estado=pendiente&pagina=1 · mis pedidos, del más reciente al más antiguo */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion);
        return Respuesta::json($this->pedidos->listarPropios(Sesion::usuario(), $peticion->query, $paginacion));
    }

    /** POST /api/pedidos · 201 con el pedido creado y su código */
    public function crear(Peticion $peticion): Respuesta
    {
        $pedido = $this->pedidos->crear(Sesion::usuario(), $peticion->json(), $peticion->ip);

        return Respuesta::json(['datos' => $pedido], 201)
            ->conCabecera('Location', '/api/pedidos/' . $pedido['codigo']);
    }

    /** GET /api/pedidos/{codigo} · detalle con líneas e historial (404 si no es tuyo) */
    public function ver(Peticion $peticion): Respuesta
    {
        // La IP va a la auditoría si el código es de otro cliente (intento IDOR)
        return Respuesta::json(['datos' => $this->pedidos->verPropio(
            Sesion::usuario(), (string) $peticion->parametro('codigo'), $peticion->ip
        )]);
    }

    /**
     * POST /api/pedidos/{codigo}/cancelar · cuerpo opcional {"motivo": "..."}
     * Es un POST y no un DELETE: el pedido no se borra, cambia de estado y queda en el historial.
     */
    public function cancelar(Peticion $peticion): Respuesta
    {
        $pedido = $this->pedidos->cancelarPropio(
            Sesion::usuario(), (string) $peticion->parametro('codigo'), $peticion->json(), $peticion->ip
        );
        return Respuesta::json(['datos' => $pedido]);
    }
}
