<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\AuthServicio;

/**
 * API de autenticación (JSON).
 *
 * Flujo en Postman: GET /api/sesion -> guardar csrf_token -> enviarlo en la
 * cabecera X-CSRF-Token de cada POST. Tras login o registro el token cambia
 * (viene en la respuesta).
 */
final class AuthController
{
    public function __construct(private readonly AuthServicio $auth = new AuthServicio())
    {
    }

    /** GET /api/sesion · ¿quién soy? + token CSRF. Público */
    public function sesion(Peticion $peticion): Respuesta
    {
        $usuario = Sesion::usuario();

        return Respuesta::json(['datos' => [
            'autenticado' => $usuario !== null,
            'usuario'     => $usuario,
            'csrf_token'  => Sesion::tokenCsrf(),
        ]]);
    }

    /** POST /api/registro · 201 cliente creado y sesión iniciada */
    public function registro(Peticion $peticion): Respuesta
    {
        $usuario = $this->auth->registrar($peticion->json(), $peticion->ip);
        Sesion::iniciarComo($usuario);

        return Respuesta::json(['datos' => [
            'usuario'    => Sesion::usuario(),
            'csrf_token' => Sesion::tokenCsrf(),
        ]], 201)->conCabecera('Location', '/api/sesion');
    }

    /** POST /api/login · 200 sesión iniciada */
    public function login(Peticion $peticion): Respuesta
    {
        $datos = $peticion->json();
        $usuario = $this->auth->iniciarSesion($datos['email'] ?? null, $datos['password'] ?? null, $peticion->ip);
        Sesion::iniciarComo($usuario);

        return Respuesta::json(['datos' => [
            'usuario'    => Sesion::usuario(),
            'csrf_token' => Sesion::tokenCsrf(),
        ]]);
    }

    /** POST /api/logout · 204 sin contenido */
    public function logout(Peticion $peticion): Respuesta
    {
        Sesion::cerrar();
        return Respuesta::sinContenido();
    }
}
