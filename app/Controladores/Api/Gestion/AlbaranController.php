<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\AlbaranServicio;

/**
 * POST /api/gestion/albaranes?referencia=ALB-2026-0042 · carga un albarán del
 * proveedor enviado como text/csv (ver el formato en AlbaranServicio y el
 * ejemplo de docs/ejemplos/albaran-ejemplo.csv). Solo administración.
 *
 * Responde 200 y no 201: el albarán no se guarda como recurso propio con su
 * URL, lo que queda son los movimientos de stock y la auditoría.
 */
final class AlbaranController
{
    public function __construct(private readonly AlbaranServicio $albaranes = new AlbaranServicio())
    {
    }

    public function cargar(Peticion $peticion): Respuesta
    {
        $resumen = $this->albaranes->cargar(
            Sesion::usuario(), $peticion->texto('text/csv'), $peticion->query['referencia'] ?? null, $peticion->ip
        );
        return Respuesta::json(['datos' => $resumen]);
    }
}
