<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Paso intermedio que se ejecuta antes del controlador (comprobar la sesión,
 * el rol, el token CSRF...). Puede cortar la petición lanzando una
 * HttpExcepcion o dejarla seguir llamando a $siguiente($peticion).
 */
interface Middleware
{
    /** @param callable(Peticion): Respuesta $siguiente */
    public function procesar(Peticion $peticion, callable $siguiente): Respuesta;
}
