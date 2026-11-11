<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\InformeStockServicio;

/**
 * Informes de stock: el almacén los genera y los envía (informes_stock.enviar)
 * y administración los revisa (informes_stock.revisar). Las reglas, en
 * InformeStockServicio.
 */
final class InformeStockController
{
    public function __construct(private readonly InformeStockServicio $informes = new InformeStockServicio())
    {
    }

    /** GET /api/gestion/informes-stock/vista-previa?tipo=stock_bajo|sin_ventas&dias=60 · calcula sin guardar */
    public function vistaPrevia(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->informes->vistaPrevia($peticion->query)]);
    }

    /** GET /api/gestion/informes-stock/vista-previa/csv?tipo=...&dias=... · la vista previa como archivo */
    public function vistaPreviaCsv(Peticion $peticion): Respuesta
    {
        return self::descarga($this->informes->csvVistaPrevia($peticion->query));
    }

    /** POST /api/gestion/informes-stock · {"tipo", "dias", "comentario"} · 201 con el informe guardado */
    public function enviar(Peticion $peticion): Respuesta
    {
        $informe = $this->informes->enviar(Sesion::usuario(), $peticion->json(), $peticion->ip);
        return Respuesta::json(['datos' => $informe], 201)
            ->conCabecera('Location', '/api/gestion/informes-stock/' . $informe['id']);
    }

    /** GET /api/gestion/informes-stock?estado=pendiente|revisado&tipo&pagina · pendientes primero */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion);
        return Respuesta::json($this->informes->listar(Sesion::usuario(), $peticion->query, $paginacion));
    }

    /** GET /api/gestion/informes-stock/{id} · con la foto de las filas */
    public function ver(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->informes->ver(Sesion::usuario(), (string) $peticion->parametro('id'))]);
    }

    /** PATCH /api/gestion/informes-stock/{id}/revisado · sin cuerpo */
    public function marcarRevisado(Peticion $peticion): Respuesta
    {
        $informe = $this->informes->marcarRevisado(Sesion::usuario(), (string) $peticion->parametro('id'), $peticion->ip);
        return Respuesta::json(['datos' => $informe]);
    }

    /** GET /api/gestion/informes-stock/{id}/csv */
    public function csv(Peticion $peticion): Respuesta
    {
        return self::descarga($this->informes->csvGuardado(Sesion::usuario(), (string) $peticion->parametro('id')));
    }

    /** @param array{contenido: string, tipo: string, nombre_archivo: string} $archivo */
    private static function descarga(array $archivo): Respuesta
    {
        return Respuesta::descarga($archivo['contenido'], $archivo['tipo'], $archivo['nombre_archivo']);
    }
}
