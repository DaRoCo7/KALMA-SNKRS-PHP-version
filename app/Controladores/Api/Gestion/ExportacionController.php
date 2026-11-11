<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\ExportacionServicio;

/**
 * Exportación de datos del área interna. Permiso datos.exportar (solo administración).
 */
final class ExportacionController
{
    public function __construct(private readonly ExportacionServicio $exportacion = new ExportacionServicio())
    {
    }

    /**
     * GET /api/gestion/exportar/pedidos?formato=csv|json&estado=entregado&desde=2026-09-01&hasta=2026-11-30
     * 200 con el archivo para descargar (kalma-pedidos-AAAAMMDD.csv o .json).
     */
    public function pedidos(Peticion $peticion): Respuesta
    {
        $archivo = $this->exportacion->pedidos(Sesion::usuario(), $peticion->query, $peticion->ip);
        return Respuesta::descarga($archivo['contenido'], $archivo['tipo'], $archivo['nombre_archivo']);
    }
}
