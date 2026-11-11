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
 * Exige sesión iniciada: 401 en la API y, en las páginas, redirección a
 * /login?volver=<página pedida>.
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
                return Respuesta::redireccion(self::urlLogin($peticion));
            }
            throw HttpExcepcion::noAutenticado();
        }

        Sesion::actualizarUsuario($actual);
        return $siguiente($peticion);
    }

    /**
     * /login con la página pedida en ?volver=, para que tras entrar el usuario
     * acabe donde quería ir y no en el catálogo. Solo en los GET: tras un POST
     * caducado no tiene sentido volver a una URL que no se puede abrir.
     * La query original se conserva; el filtro de rutas no fiables lo hace
     * AuthController::destinoSeguro al leer el parámetro.
     */
    private static function urlLogin(Peticion $peticion): string
    {
        if ($peticion->metodo !== 'GET') {
            return '/login';
        }
        $volver = $peticion->ruta;
        if ($peticion->query !== []) {
            $volver .= '?' . http_build_query($peticion->query);
        }
        return '/login?volver=' . rawurlencode($volver);
    }
}
