<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Middleware;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Repositorios\UsuarioRepositorio;
use App\Seguridad\Sesion;

/**
 * Exige sesión iniciada: 401 en la API y redirección a /login en las páginas.
 *
 * En cada petición vuelve a leer el usuario de la BD: si un administrador lo
 * desactiva o le cambia el rol, el cambio se aplica al momento y no cuando
 * caduque su sesión.
 */
final class Autenticado implements Middleware
{
    public function procesar(Peticion $peticion, callable $siguiente): Respuesta
    {
        $enSesion = Sesion::usuario();
        $actual = $enSesion === null ? null : (new UsuarioRepositorio())->buscarPorId($enSesion['id']);

        if ($actual === null || !$actual['activo']) {
            if ($enSesion !== null) {
                Sesion::cerrar();   // la cuenta se ha desactivado o ya no existe
            }
            if (!$peticion->esApi()) {
                return Respuesta::redireccion('/login');
            }
            throw HttpExcepcion::noAutenticado();
        }

        Sesion::actualizarUsuario($actual);
        return $siguiente($peticion);
    }
}
