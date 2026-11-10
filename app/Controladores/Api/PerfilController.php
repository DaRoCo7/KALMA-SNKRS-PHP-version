<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\PerfilServicio;

/**
 * API del perfil del cliente. Las dos rutas exigen sesión y el permiso
 * perfil.propio (ver config/rutas/cliente_e_informes.php).
 *
 * No hay id en la URL: el perfil es siempre el del usuario de la sesión, así
 * que no se puede leer ni editar el de otro cliente (sin riesgo de IDOR).
 */
final class PerfilController
{
    public function __construct(private readonly PerfilServicio $perfiles = new PerfilServicio())
    {
    }

    /** GET /api/perfil · mis datos (403 si la cuenta no es de cliente) */
    public function ver(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->perfiles->ver(Sesion::usuario())]);
    }

    /**
     * PATCH /api/perfil · cuerpo con los campos que cambian, ej. {"telefono": "600000102"}
     * Editables: nombre, apellidos, telefono, direccion, codigo_postal, ciudad y provincia.
     */
    public function editar(Peticion $peticion): Respuesta
    {
        $perfil = $this->perfiles->editar(Sesion::usuario(), $peticion->json(), $peticion->ip);

        // La sesión guarda el nombre que se muestra en la cabecera de la web:
        // se actualiza ya, sin esperar a la siguiente petición
        Sesion::actualizarUsuario(['nombre' => $perfil['nombre'], 'apellidos' => $perfil['apellidos']] + Sesion::usuario());

        return Respuesta::json(['datos' => $perfil]);
    }
}
