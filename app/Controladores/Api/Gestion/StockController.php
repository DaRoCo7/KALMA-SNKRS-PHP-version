<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\StockServicio;

/**
 * API de control de stock del área interna. Todas las rutas exigen el permiso
 * stock.gestionar (almacén y administración): ver config/rutas/operaciones.php.
 */
final class StockController
{
    public function __construct(private readonly StockServicio $stock = new StockServicio())
    {
    }

    /** GET /api/gestion/stock?buscar=&bajo_minimo=1&producto_id=&pagina=1 · tallas con su stock */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion, porDefecto: 20, maximo: 100);
        return Respuesta::json($this->stock->listar($peticion->query, $paginacion));
    }

    /**
     * PATCH /api/gestion/variantes/{id}/stock · {"cantidad": -2, "motivo": "..."}
     * Se envía la diferencia y no el stock final: así el ajuste queda como un
     * movimiento con signo y no pisa una venta que entre a la vez.
     */
    public function ajustar(Peticion $peticion): Respuesta
    {
        $talla = $this->stock->ajustar(
            Sesion::usuario(), (string) $peticion->parametro('id'), $peticion->json(), $peticion->ip
        );
        return Respuesta::json(['datos' => $talla]);
    }

    /** GET /api/gestion/variantes/{id}/movimientos?pagina=1 · historial de la talla, lo más reciente primero */
    public function movimientos(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion, porDefecto: 20, maximo: 100);
        return Respuesta::json($this->stock->movimientos((string) $peticion->parametro('id'), $paginacion));
    }
}
