<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Consultas del catálogo. Los filtros se combinan con AND y cada valor viaja
 * como parámetro de la consulta preparada; el SQL solo se compone con trozos
 * fijos escritos aquí (nunca con texto del usuario).
 */
final class ProductoRepositorio
{
    /**
     * Ya lanzada: sin fecha de lanzamiento o con una que ya llegó. Un lanzamiento
     * anunciado se enseña en su página, pero no se vende ni sale en el catálogo.
     * Se decide con el reloj de la BD (CURRENT_DATE) para que todas las consultas
     * cambien a la vez a medianoche, sin depender de la hora del servidor PHP.
     */
    public const LANZADO = '(p.fecha_lanzamiento IS NULL OR p.fecha_lanzamiento <= CURRENT_DATE)';

    /**
     * Lo que se vende hoy: producto activo y ya lanzado. Es LA condición del
     * catálogo: el listado, sus filtros, el menú y los informes de stock la
     * comparten para que nunca cuenten productos distintos (si los filtros
     * contaran los lanzamientos, Jordan diría 9 y al filtrar saldrían 3).
     * La tabla productos tiene que llevar el alias p en la consulta.
     */
    public const A_LA_VENTA = 'p.activo AND ' . self::LANZADO;

    /** Ordenaciones permitidas: el cliente elige la clave, nunca el SQL */
    public const ORDENES = [
        'novedades'   => 'p.fecha_creacion DESC, p.id DESC',
        'precio_asc'  => 'p.precio ASC, p.id',
        'precio_desc' => 'p.precio DESC, p.id',
        'nombre'      => 'p.nombre ASC, p.id',
    ];

    /** Listado paginado con stock total y tallas disponibles de cada producto */
    public function buscar(array $filtros, string $orden, int $limite, int $desplazamiento): array
    {
        [$where, $parametros] = $this->condiciones($filtros);

        $sql = <<<SQL
            SELECT p.id, p.nombre, p.colorway, p.codigo_marca, p.slug, p.precio, p.personalizable, p.imagen,
                   c.id AS categoria_id, c.nombre AS categoria,
                   col.nombre AS color, col.codigo_hex,
                   s.stock_total, s.tallas
            FROM productos p
            JOIN categorias c ON c.id = p.categoria_id
            JOIN colores col  ON col.id = p.color_id
            -- Por cada producto: stock sumado y lista de tallas con existencias
            CROSS JOIN LATERAL (
                SELECT COALESCE(sum(v.stock), 0) AS stock_total,
                       COALESCE(json_agg(v.talla ORDER BY v.talla) FILTER (WHERE v.stock > 0), '[]') AS tallas
                FROM variantes v
                WHERE v.producto_id = p.id AND v.activo
            ) s
            WHERE {$where}
            ORDER BY {$this->orden($orden)}
            LIMIT :limite OFFSET :desplazamiento
            SQL;

        $consulta = BaseDatos::conexion()->prepare($sql);
        $consulta->execute($parametros + ['limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contar(array $filtros): int
    {
        [$where, $parametros] = $this->condiciones($filtros);
        $consulta = BaseDatos::conexion()->prepare("SELECT count(*) FROM productos p WHERE {$where}");
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    public function buscarPorSlug(string $slug): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT p.id, p.nombre, p.colorway, p.codigo_marca, p.slug, p.descripcion, p.precio,
                    p.personalizable, p.imagen, c.id AS categoria_id, c.nombre AS categoria,
                    col.nombre AS color, col.codigo_hex, p.fecha_lanzamiento,
                    -- Se decide con el reloj de la base de datos, el mismo que filtra el catálogo
                    COALESCE(p.fecha_lanzamiento > CURRENT_DATE, false) AS por_lanzar
             FROM productos p
             JOIN categorias c ON c.id = p.categoria_id
             JOIN colores col  ON col.id = p.color_id
             WHERE p.slug = :slug AND p.activo'
        );
        $consulta->execute(['slug' => $slug]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Tallas de un producto con su stock. El id de la variante es lo que el
     * front envía al hacer un pedido; el RFID es interno y no se publica.
     */
    public function tallas(int $productoId): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT id, talla, stock FROM variantes WHERE producto_id = :id AND activo ORDER BY talla'
        );
        $consulta->execute(['id' => $productoId]);
        return $consulta->fetchAll();
    }

    /** Otros colores del mismo modelo: comparten prefijo de código (CW2288-111, CW2288-001) */
    public function otrosColores(int $productoId, string $codigoMarca): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            "SELECT p.slug, p.colorway, p.codigo_marca, p.imagen, col.nombre AS color, col.codigo_hex
             FROM productos p JOIN colores col ON col.id = p.color_id
             WHERE p.activo AND p.id <> :id
               AND split_part(p.codigo_marca, '-', 1) = split_part(:codigo, '-', 1)
             ORDER BY p.id"
        );
        $consulta->execute(['id' => $productoId, 'codigo' => $codigoMarca]);
        return $consulta->fetchAll();
    }

    /**
     * Lanzamientos anunciados: zapatillas con fecha de lanzamiento futura, del
     * más cercano al más lejano. Todavía no se pueden comprar.
     * Sin límite devuelve todos (LIMIT NULL en PostgreSQL no limita).
     */
    public function proximosLanzamientos(?int $limite = null): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT p.id, p.nombre, p.colorway, p.codigo_marca, p.slug, p.descripcion, p.precio,
                    p.imagen, p.fecha_lanzamiento
             FROM productos p
             WHERE p.activo AND p.fecha_lanzamiento > CURRENT_DATE
             ORDER BY p.fecha_lanzamiento, p.id
             LIMIT :limite'
        );
        $consulta->execute(['limite' => $limite]);
        return $consulta->fetchAll();
    }

    /** Opciones de personalización que se pueden elegir hoy (las desactivadas no salen) */
    public function opcionesPersonalizacion(): array
    {
        return BaseDatos::conexion()->query(
            'SELECT id, tipo, nombre, descripcion, afecta_zapatilla, max_caracteres, recargo
             FROM opciones_personalizacion WHERE activo ORDER BY tipo, id'
        )->fetchAll();
    }

    /**
     * Solo id y nombre de las categorías con algo a la venta. Es la consulta que
     * hace la cabecera en cada página, por eso no cuenta productos ni junta tablas.
     */
    public function categoriasConProductos(): array
    {
        return BaseDatos::conexion()->query(
            'SELECT c.id, c.nombre FROM categorias c
             WHERE EXISTS (SELECT 1 FROM productos p WHERE p.categoria_id = c.id AND ' . self::A_LA_VENTA . ')
             ORDER BY c.nombre'
        )->fetchAll();
    }

    /**
     * Valores para construir los filtros de la interfaz. Todos salen de lo que
     * el listado puede devolver (A_LA_VENTA): un número junto a una categoría,
     * un color o un precio que luego no aparece al filtrar sería mentira.
     */
    public function valoresFiltros(): array
    {
        $pdo = BaseDatos::conexion();
        $aLaVenta = self::A_LA_VENTA;

        return [
            'categorias' => $pdo->query(
                "SELECT c.id, c.nombre, count(p.id) AS productos
                 FROM categorias c LEFT JOIN productos p ON p.categoria_id = c.id AND {$aLaVenta}
                 GROUP BY c.id ORDER BY c.nombre"
            )->fetchAll(),
            'colores' => $pdo->query(
                "SELECT col.id, col.nombre, col.codigo_hex
                 FROM colores col WHERE EXISTS (SELECT 1 FROM productos p WHERE p.color_id = col.id AND {$aLaVenta})
                 ORDER BY col.nombre"
            )->fetchAll(),
            'tallas' => $pdo->query(
                "SELECT DISTINCT v.talla FROM variantes v JOIN productos p ON p.id = v.producto_id
                 WHERE v.activo AND v.stock > 0 AND {$aLaVenta} ORDER BY v.talla"
            )->fetchAll(\PDO::FETCH_COLUMN),
            'precio' => $pdo->query(
                "SELECT min(p.precio) AS minimo, max(p.precio) AS maximo FROM productos p WHERE {$aLaVenta}"
            )->fetch(),
        ];
    }

    /**
     * WHERE a partir de los filtros ya validados por el servicio.
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function condiciones(array $filtros): array
    {
        // Los lanzamientos anunciados no salen en el catálogo hasta su fecha
        $sql = [self::A_LA_VENTA];
        $parametros = [];

        if (isset($filtros['buscar'])) {
            // % y _ escritos por el usuario se buscan como texto, no como comodines
            $patron = '%' . addcslashes($filtros['buscar'], '%_\\') . '%';
            $sql[] = '(p.nombre ILIKE :b1 OR p.colorway ILIKE :b2 OR p.codigo_marca ILIKE :b3 OR p.descripcion ILIKE :b4)';
            $parametros += ['b1' => $patron, 'b2' => $patron, 'b3' => $patron, 'b4' => $patron];
        }
        if (isset($filtros['categoria'])) {
            $sql[] = 'p.categoria_id = :categoria';
            $parametros['categoria'] = $filtros['categoria'];
        }
        if (isset($filtros['color'])) {
            $sql[] = 'p.color_id = :color';
            $parametros['color'] = $filtros['color'];
        }
        if (isset($filtros['talla'])) {
            $sql[] = 'EXISTS (SELECT 1 FROM variantes v WHERE v.producto_id = p.id AND v.activo
                              AND v.stock > 0 AND v.talla = :talla)';
            $parametros['talla'] = $filtros['talla'];
        }
        if (isset($filtros['precio_min'])) {
            $sql[] = 'p.precio >= :precio_min';
            $parametros['precio_min'] = $filtros['precio_min'];
        }
        if (isset($filtros['precio_max'])) {
            $sql[] = 'p.precio <= :precio_max';
            $parametros['precio_max'] = $filtros['precio_max'];
        }
        if (isset($filtros['personalizable'])) {
            $sql[] = 'p.personalizable = :personalizable';
            // PDO enviaría false como cadena vacía: se pasa como texto que PostgreSQL entiende
            $parametros['personalizable'] = $filtros['personalizable'] ? 'true' : 'false';
        }
        if (!empty($filtros['disponible'])) {
            $sql[] = 'EXISTS (SELECT 1 FROM variantes v WHERE v.producto_id = p.id AND v.activo AND v.stock > 0)';
        }

        return [implode(' AND ', $sql), $parametros];
    }

    private function orden(string $clave): string
    {
        return self::ORDENES[$clave] ?? self::ORDENES['novedades'];
    }
}
