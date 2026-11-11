<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;

/**
 * Página de lanzamientos. Como el catálogo, solo sirve el esqueleto: las
 * zapatillas anunciadas y su ficha las carga el JavaScript desde la API
 * (contrato, punto 10).
 */
final class LanzamientosController
{
    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('lanzamientos', ['titulo' => 'Lanzamientos']);
    }
}
