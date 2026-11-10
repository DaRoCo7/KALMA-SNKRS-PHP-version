<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;

/**
 * GET /health · contrato con el módulo de Despliegue.
 *
 * Distingue la disponibilidad de la aplicación de la de la base de datos:
 *   200 {"app":"disponible","base_datos":"disponible"}
 *   503 {"app":"disponible","base_datos":"no_disponible"}
 * El healthcheck del servicio app de compose.yaml depende de este código HTTP.
 */
final class SaludController
{
    public function comprobar(Peticion $peticion): Respuesta
    {
        $baseDatos = BaseDatos::disponible() ? 'disponible' : 'no_disponible';

        return Respuesta::json(
            ['app' => 'disponible', 'base_datos' => $baseDatos],
            $baseDatos === 'disponible' ? 200 : 503
        );
    }
}
