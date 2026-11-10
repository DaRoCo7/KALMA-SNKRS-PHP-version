<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;

/**
 * Páginas HTML del catálogo (las genera el servidor).
 * De momento solo la estructura: los productos llegan en la fase del catálogo.
 */
final class CatalogoController
{
    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('catalogo', ['titulo' => 'Catálogo de zapatillas']);
    }
}
