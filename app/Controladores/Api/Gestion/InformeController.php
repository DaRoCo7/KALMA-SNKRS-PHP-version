<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Servicios\InformeServicio;

/**
 * Panel resumen e informes del área interna. Permiso informes.ver (solo administración).
 */
final class InformeController
{
    public function __construct(private readonly InformeServicio $informes = new InformeServicio())
    {
    }

    /** GET /api/gestion/resumen · indicadores del panel calculados sobre los datos reales */
    public function resumen(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->informes->resumen()]);
    }

    /**
     * GET /api/gestion/informes/{nombre}?desde=2026-09-01&hasta=2026-11-30
     * Informes: ventas-por-modelo, ventas-por-mes y personalizaciones (sin pedidos cancelados).
     */
    public function ver(Peticion $peticion): Respuesta
    {
        $informe = $this->informes->informe((string) $peticion->parametro('nombre'), $peticion->query);
        return Respuesta::json(['datos' => $informe]);
    }
}
