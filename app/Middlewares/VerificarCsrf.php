<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Middleware;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;

/**
 * Middleware global contra CSRF.
 *
 * Como la sesión viaja en una cookie, otra web podría intentar que el
 * navegador de un usuario logueado envíe un POST a nuestra web. Para evitarlo,
 * toda petición que modifica datos debe traer el token secreto de la sesión:
 *   - formularios HTML: campo oculto _csrf
 *   - API: cabecera X-CSRF-Token (se obtiene en GET /api/sesion)
 */
final class VerificarCsrf implements Middleware
{
    private const METODOS_SEGUROS = ['GET', 'HEAD', 'OPTIONS'];

    public function procesar(Peticion $peticion, callable $siguiente): Respuesta
    {
        if (!in_array($peticion->metodo, self::METODOS_SEGUROS, true)) {
            $token = $peticion->cabecera('x-csrf-token') ?? $peticion->campoFormulario('_csrf');

            if (!is_string($token) || !Sesion::tokenCsrfValido($token)) {
                throw HttpExcepcion::prohibido('Token CSRF ausente o no válido');
            }
        }
        return $siguiente($peticion);
    }
}
