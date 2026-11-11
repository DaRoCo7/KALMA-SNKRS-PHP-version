<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Repositorios\UsuarioRepositorio;

/**
 * GET /api/usuarios?pagina=1&por_pagina=10&rol=cliente · solo administración.
 * Listado paginado de cuentas (nunca incluye hashes de contraseña).
 */
final class UsuarioController
{
    private const ROLES = ['admin', 'almacen', 'cliente'];

    public function __construct(private readonly UsuarioRepositorio $usuarios = new UsuarioRepositorio())
    {
    }

    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion);

        $rol = $peticion->query['rol'] ?? null;
        if ($rol !== null && !in_array($rol, self::ROLES, true)) {
            throw HttpExcepcion::peticionIncorrecta('Filtro no válido', ['rol' => 'Valores: ' . implode(', ', self::ROLES)]);
        }

        $total = $this->usuarios->contar($rol);
        $filas = $this->usuarios->listar($rol, $paginacion->porPagina, $paginacion->offset());

        return Respuesta::json(['datos' => $filas, 'paginacion' => $paginacion->meta($total)]);
    }
}
