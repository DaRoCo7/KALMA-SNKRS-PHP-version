<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;

/**
 * Página «Sobre nosotros». Es texto fijo de la casa: no consulta la base de
 * datos, así que solo pasa el título a la vista.
 */
final class NosotrosController
{
    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('sobre-nosotros', ['titulo' => 'Sobre nosotros']);
    }
}
