<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Servicios\ConsultaServicio;

/**
 * POST /api/consultas · formulario de contacto público (sin sesión).
 *
 * Al ser público, la ruta lleva un límite por IP (LimitarTasa) para que nadie
 * llene la tabla de consultas con un script. El token CSRF se sigue pidiendo:
 * lo da GET /api/sesion también a los visitantes anónimos.
 */
final class ConsultaController
{
    public function __construct(private readonly ConsultaServicio $consultas = new ConsultaServicio())
    {
    }

    /** Cuerpo: {nombre, email, asunto, mensaje} · 201 con la consulta guardada */
    public function crear(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->consultas->crear($peticion->json(), $peticion->ip)], 201);
    }
}
