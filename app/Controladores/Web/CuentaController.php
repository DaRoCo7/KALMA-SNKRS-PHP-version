<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\PerfilServicio;

/**
 * Área del cliente: sus pedidos y sus datos. Como el catálogo, solo sirve el
 * esqueleto; public/js/cuenta.js pide los pedidos y el perfil a la API
 * (contrato, punto 10).
 *
 * Las cuentas de la tienda (admin y almacén) no tienen perfil de cliente: la
 * API les respondería 403 y la página se quedaría a medias, así que aquí se
 * detecta antes y se les manda a su panel.
 */
final class CuentaController
{
    public function __construct(private readonly PerfilServicio $perfiles = new PerfilServicio())
    {
    }

    /** GET /cuenta (exige sesión: middleware Autenticado) */
    public function index(Peticion $peticion): Respuesta
    {
        $usuario = Sesion::usuario();

        // Se pregunta al servicio y no solo por el rol: lo que decide si la
        // página funciona es que exista el perfil de cliente que lee la API
        try {
            $this->perfiles->ver($usuario);
            $esCliente = true;
        } catch (HttpExcepcion $error) {
            if ($error->estado !== 403) {
                throw $error;
            }
            $esCliente = false;
        }

        return Respuesta::vista('cuenta', [
            'titulo'    => 'Mi cuenta',
            'nombre'    => $usuario['nombre'],
            'esCliente' => $esCliente,
        ]);
    }
}
