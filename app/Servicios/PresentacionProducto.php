<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\Formato;
use App\Repositorios\CatalogoInternoRepositorio;

/**
 * Convierte las filas de la BD en el JSON de una zapatilla del catálogo
 * interno (resumen del listado y detalle con sus tallas).
 *
 * Vive aparte de GestionProductoServicio por la misma razón que
 * PresentacionPedido: el servicio decide qué se puede hacer y esta clase
 * decide qué campos y con qué tipos ve el panel, así el listado, el alta y la
 * edición devuelven siempre la misma forma.
 *
 * A diferencia del catálogo público (CatalogoServicio), aquí sí salen el RFID
 * de cada talla y los productos desactivados: solo lo ve el personal.
 */
final class PresentacionProducto
{
    public function __construct(private readonly CatalogoInternoRepositorio $catalogo = new CatalogoInternoRepositorio())
    {
    }

    /** Datos de un producto en el listado interno (PostgreSQL devuelve los NUMERIC como texto) */
    public function resumen(array $p): array
    {
        return [
            'id'                  => (int) $p['id'],
            'codigo_marca'        => $p['codigo_marca'],
            'nombre'              => $p['nombre'],
            'colorway'            => $p['colorway'],
            'slug'                => $p['slug'],
            'precio'              => (float) $p['precio'],
            'personalizable'      => $p['personalizable'],
            'activo'              => $p['activo'],
            'imagen'              => $p['imagen'] === null ? null : '/' . $p['imagen'],
            'categoria'           => ['id' => (int) $p['categoria_id'], 'nombre' => $p['categoria']],
            'color'               => ['id' => (int) $p['color_id'], 'nombre' => $p['color'], 'hex' => $p['codigo_hex']],
            'stock_total'         => (int) $p['stock_total'],
            'num_tallas'          => (int) $p['num_tallas'],
            // AAAA-MM-DD o null; si es futura, la zapatilla está anunciada y no se vende todavía
            'fecha_lanzamiento'   => $p['fecha_lanzamiento'],
            'fecha_actualizacion' => Formato::fecha($p['fecha_actualizacion']),
        ];
    }

    /** Producto con descripción y todas sus tallas (también las desactivadas), con su RFID */
    public function detalle(array $p): array
    {
        return $this->resumen($p) + [
            'descripcion'    => $p['descripcion'],
            'fecha_creacion' => Formato::fecha($p['fecha_creacion']),
            'variantes'      => array_map(static fn (array $v): array => [
                'id'           => (int) $v['id'],
                'talla'        => (float) $v['talla'],
                'stock'        => (int) $v['stock'],
                'stock_minimo' => (int) $v['stock_minimo'],
                'rfid_tienda'  => $v['rfid_tienda'],
                'activo'       => $v['activo'],
            ], $this->catalogo->variantes((int) $p['id'])),
        ];
    }
}
