<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\ConsultaServicio;

/**
 * Gestión de las consultas del formulario de contacto en el área interna.
 * Permiso consultas.gestionar (solo administración).
 */
final class ConsultaController
{
    public function __construct(private readonly ConsultaServicio $consultas = new ConsultaServicio())
    {
    }

    /** GET /api/gestion/consultas?estado=pendiente&pagina=1 · de la más reciente a la más antigua */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion);
        return Respuesta::json($this->consultas->listar($peticion->query, $paginacion));
    }

    /** PATCH /api/gestion/consultas/{id} · cuerpo {"estado": "pendiente" | "respondida" | "cerrada"} */
    public function cambiarEstado(Peticion $peticion): Respuesta
    {
        $consulta = $this->consultas->cambiarEstado(
            Sesion::usuario(), (string) $peticion->parametro('id'), $peticion->json(), $peticion->ip
        );
        return Respuesta::json(['datos' => $consulta]);
    }
}
