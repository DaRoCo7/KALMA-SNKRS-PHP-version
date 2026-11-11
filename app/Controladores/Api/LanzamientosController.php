<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Servicios\CatalogoServicio;

/**
 * Zapatillas anunciadas que todavía no se venden (público, sin sesión). Van
 * aparte de /api/productos porque el catálogo solo lista lo que se puede pedir.
 */
final class LanzamientosController
{
    public function __construct(private readonly CatalogoServicio $catalogo = new CatalogoServicio())
    {
    }

    /** GET /api/lanzamientos · todos los lanzamientos futuros, del más cercano al más lejano */
    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->catalogo->lanzamientos()]);
    }
}
