<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\GestionProductoServicio;

/**
 * API del catálogo interno: alta, edición y desactivación de zapatillas.
 * Todas las rutas exigen sesión y el permiso productos.gestionar (solo
 * administración, ver config/rutas/catalogo_interno.php).
 */
final class ProductoController
{
    public function __construct(private readonly GestionProductoServicio $productos = new GestionProductoServicio())
    {
    }

    /** GET /api/gestion/productos?buscar=dunk&activo=false&pagina=1 · incluye los desactivados */
    public function index(Peticion $peticion): Respuesta
    {
        $paginacion = Paginacion::desdePeticion($peticion, porDefecto: 20);
        return Respuesta::json($this->productos->listar($peticion->query, $paginacion));
    }

    /** GET /api/gestion/productos/{id} · producto con todas sus tallas, su stock y su RFID */
    public function ver(Peticion $peticion): Respuesta
    {
        return Respuesta::json(['datos' => $this->productos->ver((string) $peticion->parametro('id'))]);
    }

    /** POST /api/gestion/productos · 201 con el producto creado y sus tallas */
    public function crear(Peticion $peticion): Respuesta
    {
        $producto = $this->productos->crear(Sesion::usuario(), $peticion->json(), $peticion->ip);

        return Respuesta::json(['datos' => $producto], 201)
            ->conCabecera('Location', '/api/gestion/productos/' . $producto['id']);
    }

    /** PATCH /api/gestion/productos/{id} · cambia solo los campos enviados */
    public function editar(Peticion $peticion): Respuesta
    {
        $producto = $this->productos->editar(
            Sesion::usuario(), (string) $peticion->parametro('id'), $peticion->json(), $peticion->ip
        );
        return Respuesta::json(['datos' => $producto]);
    }

    /** DELETE /api/gestion/productos/{id} · 204: lo desactiva, no lo borra */
    public function desactivar(Peticion $peticion): Respuesta
    {
        $this->productos->desactivar(Sesion::usuario(), (string) $peticion->parametro('id'), $peticion->ip);
        return Respuesta::sinContenido();
    }
}
