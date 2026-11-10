<?php

declare(strict_types=1);

namespace App\Controladores\Api;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;

/**
 * GET /api · punto de entrada de la API JSON.
 * Sirve como primera petición de prueba en Postman.
 */
final class InicioController
{
    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => [
            'api'     => 'KALMA SNKRS',
            'version' => '1.0',
            'formato' => 'Éxito: {"datos": ...} · Error: {"error": {"codigo": ..., "mensaje": ...}}',
        ]]);
    }
}
