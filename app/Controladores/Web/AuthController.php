<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\AuthServicio;

/**
 * Páginas de login, registro y cierre de sesión (formularios HTML).
 * Usan el mismo AuthServicio que la API; solo cambia la forma de responder:
 * si hay un error se vuelve a mostrar el formulario con los mensajes.
 */
final class AuthController
{
    public function __construct(private readonly AuthServicio $auth = new AuthServicio())
    {
    }

    /** GET /login */
    public function formularioLogin(Peticion $peticion): Respuesta
    {
        if (Sesion::usuario() !== null) {
            return Respuesta::redireccion('/catalogo');
        }
        return Respuesta::vista('auth/login', ['email' => '', 'error' => null]);
    }

    /** POST /login */
    public function login(Peticion $peticion): Respuesta
    {
        $datos = $peticion->entrada();

        try {
            $usuario = $this->auth->iniciarSesion($datos['email'] ?? null, $datos['password'] ?? null, $peticion->ip);
        } catch (HttpExcepcion $error) {
            return Respuesta::vista('auth/login', [
                'email' => is_string($datos['email'] ?? null) ? $datos['email'] : '',
                'error' => $error->getMessage(),
            ], $error->estado);
        }

        Sesion::iniciarComo($usuario);
        return Respuesta::redireccion('/catalogo');
    }

    /** GET /registro */
    public function formularioRegistro(Peticion $peticion): Respuesta
    {
        if (Sesion::usuario() !== null) {
            return Respuesta::redireccion('/catalogo');
        }
        return Respuesta::vista('auth/registro', ['datos' => [], 'errores' => [], 'error' => null]);
    }

    /** POST /registro */
    public function registro(Peticion $peticion): Respuesta
    {
        $datos = $peticion->entrada();

        try {
            $usuario = $this->auth->registrar($datos, $peticion->ip);
        } catch (HttpExcepcion $error) {
            unset($datos['password']);   // la contraseña nunca se devuelve al formulario
            return Respuesta::vista('auth/registro', [
                'datos'   => array_filter($datos, 'is_string'),
                'errores' => $error->detalles['errores'] ?? [],
                'error'   => $error->getMessage(),
            ], $error->estado);
        }

        Sesion::iniciarComo($usuario);
        return Respuesta::redireccion('/catalogo');
    }

    /** POST /logout */
    public function logout(Peticion $peticion): Respuesta
    {
        Sesion::cerrar();
        return Respuesta::redireccion('/catalogo');
    }
}
