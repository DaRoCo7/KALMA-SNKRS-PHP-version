<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;

/**
 * Página del catálogo. Solo sirve el esqueleto: las zapatillas, la ficha y la
 * cesta las carga el JavaScript desde la API (contrato, punto 10).
 */
final class CatalogoController
{
    /**
     * Textos de los órdenes que admite GET /api/productos. Las claves son las de
     * ProductoRepositorio::ORDENES; se escriben aquí porque el texto es cosa de la página.
     */
    private const ORDENES = [
        'novedades'   => 'Novedades',
        'precio_asc'  => 'Precio: de menor a mayor',
        'precio_desc' => 'Precio: de mayor a menor',
        'nombre'      => 'Nombre (A-Z)',
    ];

    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('catalogo', [
            'titulo'  => 'Catálogo de zapatillas',
            'ordenes' => self::ORDENES,
        ]);
    }
}
