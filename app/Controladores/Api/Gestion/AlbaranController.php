<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\AlbaranServicio;

/**
 * Albaranes del proveedor.
 *
 * POST /api/gestion/albaranes?referencia=ALB-2026-0042 · carga un albarán
 * enviado como text/csv (ver el formato en AlbaranServicio y el ejemplo de
 * docs/ejemplos/albaran-ejemplo.csv). Solo administración.
 *
 * Responde 200 y no 201: el albarán no se guarda como recurso propio con su
 * URL, lo que queda son los movimientos de stock y la auditoría. De ahí salen
 * también la lista y el detalle (GET), que puede consultar el almacén.
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

    /** GET /api/gestion/albaranes?pagina=1 · albaranes aceptados, del más reciente al más antiguo */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion, porDefecto: 20);
        return Respuesta::json($this->albaranes->listar($paginacion));
    }

    /** GET /api/gestion/albaranes/detalle?referencia=ALB-2026-0042 · resumen y tallas que entraron */
    public function ver(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->albaranes->ver($peticion->query['referencia'] ?? null)]);
    }
}
