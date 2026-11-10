<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Nucleo\Middleware;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;

/**
 * Middleware global: arranca la sesión en todas las rutas salvo /health
 * (el healthcheck se ejecuta cada 10 s y no debe crear sesiones).
 */
final class IniciarSesion implements Middleware
{
    public function procesar(Peticion $peticion, callable $siguiente): Respuesta
    {
        if ($peticion->ruta !== '/health') {
            Sesion::iniciar($peticion);
        }
        return $siguiente($peticion);
    }
}
