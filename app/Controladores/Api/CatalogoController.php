<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Servicios\CatalogoServicio;

/**
 * API pública del catálogo (no necesita sesión). Es la que consume el frontend.
 */
final class CatalogoController
{
    public function __construct(private readonly CatalogoServicio $catalogo = new CatalogoServicio())
    {
    }

    /** GET /api/productos · búsqueda, filtros combinables, orden y paginación */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion, porDefecto: 12);
        return Respuesta::json($this->catalogo->listar($peticion->query, $paginacion));
    }

    /** GET /api/productos/{slug} · ficha con tallas, stock y otros colores del mismo modelo */
    public function ver(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->catalogo->ver((string) $peticion->parametro('slug'))]);
    }

    /** GET /api/catalogo/filtros · valores para construir los filtros de la interfaz */
    public function filtros(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->catalogo->valoresFiltros()]);
    }
}
