<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Consultas del catálogo interno (área de administración): a diferencia del
 * catálogo público (ProductoRepositorio), aquí se ven también los productos y
 * las tallas desactivados, el RFID de cada talla y el stock mínimo.
 *
 * Solo SQL: las reglas (qué se puede cambiar, cómo se valida un albarán) están
 * en GestionProductoServicio y AlbaranServicio.
 */
final class CatalogoInternoRepositorio
{
    /** Datos del producto con su categoría, su color y el resumen de sus tallas */
    private const COLUMNAS = 'p.id, p.codigo_marca, p.nombre, p.colorway, p.slug, p.descripcion, p.precio,
        p.personalizable, p.imagen, p.activo, p.fecha_creacion, p.fecha_actualizacion,
        c.id AS categoria_id, c.nombre AS categoria,
        col.id AS color_id, col.nombre AS color, col.codigo_hex,
        s.stock_total, s.num_tallas';

    // El stock interno cuenta TODAS las tallas, también las desactivadas: son
    // pares que están físicamente en el almacén aunque no se vendan
    private const DESDE = 'FROM productos p
        JOIN categorias c ON c.id = p.categoria_id
        JOIN colores col  ON col.id = p.color_id
        CROSS JOIN LATERAL (
            SELECT COALESCE(sum(v.stock), 0) AS stock_total, count(*) AS num_tallas
            FROM variantes v WHERE v.producto_id = p.id
        ) s';

    /** Columnas que se pueden editar con PATCH: el SQL solo se compone con estos nombres fijos */
    public const EDITABLES = ['nombre', 'colorway', 'descripcion', 'precio', 'personalizable', 'categoria_id', 'color_id', 'activo'];

    /**
     * Listado paginado, de la zapatilla más reciente a la más antigua.
     *
     * @param array{buscar?: string, activo?: bool} $filtros
     */
    public function listar(array $filtros, int $limite, int $desplazamiento): array
    {
        [$where, $parametros] = $this->condiciones($filtros);

        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ' ' . self::DESDE . '
             WHERE ' . $where . '
             ORDER BY p.fecha_creacion DESC, p.id DESC
             LIMIT :limite OFFSET :desplazamiento'
        );
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

    /** Producto por id, esté activo o no */
    public function buscarPorId(int $id): ?array
    {
        $consulta = BaseDatos::conexion()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE . ' WHERE p.id = :id');
        $consulta->execute(['id' => $id]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Lee y BLOQUEA la fila del producto hasta el final de la transacción.
     * Así dos administradores que editan la misma zapatilla a la vez no se
     * pisan, y la auditoría guarda el precio "antes" que de verdad había.
     * Debe llamarse dentro de BaseDatos::transaccion().
     */
    public function bloquear(int $id): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT id, codigo_marca, nombre, colorway, descripcion, precio, personalizable,
                    categoria_id, color_id, activo
             FROM productos WHERE id = :id FOR UPDATE'
        );
        $consulta->execute(['id' => $id]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Productos de una marca a partir de su código de estilo.
     *
     * @param list<string> $codigos ya validados con ^[A-Z0-9]{6}-[0-9]{3}$, por eso
     *                              se pueden escribir dentro del array de texto {A,B}
     * @param bool $bloquear FOR UPDATE: un albarán que crea tallas de una zapatilla
     *                       espera a que termine otro que esté haciendo lo mismo
     * @return array<string, array> indexados por código de marca
     */
    public function buscarPorCodigos(int $marcaId, array $codigos, bool $bloquear = false): array
    {
        if ($codigos === []) {
            return [];
        }

        // Orden por id al bloquear: dos albaranes con las mismas zapatillas no se esperan el uno al otro
        $sql = 'SELECT id, codigo_marca, activo FROM productos
                WHERE marca_id = :marca AND codigo_marca = ANY(CAST(:codigos AS varchar[]))
                ORDER BY id';
        if ($bloquear) {
            $sql .= ' FOR UPDATE';
        }

        $consulta = BaseDatos::conexion()->prepare($sql);
        $consulta->execute(['marca' => $marcaId, 'codigos' => '{' . implode(',', $codigos) . '}']);

        $productos = [];
        foreach ($consulta->fetchAll() as $fila) {
            $productos[$fila['codigo_marca']] = $fila;
        }
        return $productos;
    }

    /** Todas las tallas de un producto, también las desactivadas, con su RFID */
    public function variantes(int $productoId): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT id, talla, stock, stock_minimo, rfid_tienda, activo
             FROM variantes WHERE producto_id = :id ORDER BY talla'
        );
        $consulta->execute(['id' => $productoId]);
        return $consulta->fetchAll();
    }

    /** @return list<array{id: int, nombre: string}> */
    public function categorias(): array
    {
        return BaseDatos::conexion()->query('SELECT id, nombre FROM categorias ORDER BY nombre')->fetchAll();
    }

    /** @return list<array{id: int, nombre: string}> */
    public function colores(): array
    {
        return BaseDatos::conexion()->query('SELECT id, nombre FROM colores ORDER BY nombre')->fetchAll();
    }

    public function marcaId(string $nombre): ?int
    {
        $consulta = BaseDatos::conexion()->prepare('SELECT id FROM marcas WHERE nombre = :nombre');
        $consulta->execute(['nombre' => $nombre]);
        $id = $consulta->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Da de alta una zapatilla. El slug y la ruta de la imagen se calculan en
     * SQL con las MISMAS fórmulas que database/seeds/catalogo.sql, para que las
     * zapatillas nuevas tengan URLs y fotos con el mismo formato que las de la semilla:
     *   "Nike Dunk Low Retro" + "DD1391-102" -> nike-dunk-low-retro-dd1391-102
     *   imagen -> img/productos/dd1391-102.webp (la foto se copia con ese nombre: no hay subida de archivos)
     *
     * Si el código o el slug ya existen, PostgreSQL lanza el error 23505 (UNIQUE)
     * y el servicio lo convierte en un 409.
     *
     * @return array{id: int, slug: string}
     */
    public function crear(int $marcaId, array $datos): array
    {
        // Cada parámetro se usa una sola vez (nombre_slug, codigo_slug...): con las
        // consultas preparadas reales de PostgreSQL no se puede repetir un nombre
        $consulta = BaseDatos::conexion()->prepare(
            "INSERT INTO productos (categoria_id, marca_id, color_id, nombre, colorway, codigo_marca,
                                    slug, descripcion, precio, personalizable, imagen)
             VALUES (:categoria_id, :marca_id, :color_id, :nombre, :colorway, :codigo_marca,
                     trim(BOTH '-' FROM regexp_replace(
                         lower(CAST(:nombre_slug AS text) || ' ' || CAST(:codigo_slug AS text)), '[^a-z0-9]+', '-', 'g')),
                     :descripcion, :precio, :personalizable,
                     'img/productos/' || lower(CAST(:codigo_imagen AS text)) || '.webp')
             RETURNING id, slug"
        );
        $consulta->execute([
            'categoria_id'   => $datos['categoria_id'],
            'marca_id'       => $marcaId,
            'color_id'       => $datos['color_id'],
            'nombre'         => $datos['nombre'],
            'colorway'       => $datos['colorway'],
            'codigo_marca'   => $datos['codigo_marca'],
            'nombre_slug'    => $datos['nombre'],
            'codigo_slug'    => $datos['codigo_marca'],
            'descripcion'    => $datos['descripcion'] ?? null,
            'precio'         => $datos['precio'],
            'personalizable' => $datos['personalizable'] ? 'true' : 'false',   // PDO enviaría false como cadena vacía
            'codigo_imagen'  => $datos['codigo_marca'],
        ]);

        $fila = $consulta->fetch();
        return ['id' => (int) $fila['id'], 'slug' => $fila['slug']];
    }

    /**
     * Crea una talla con stock 0. El stock nunca se pone aquí: las unidades
     * entran después con StockRepositorio::mover() para que quede su movimiento.
     *
     * El RFID se calcula en SQL con la MISMA fórmula que la semilla:
     *   4B4C4D ("KLM") + id del producto (6 hex) + talla × 10 (4 hex) + serie 00000001
     * Como (producto, talla) es único, el RFID también lo es.
     *
     * @return int id de la talla (variante) creada
     */
    public function crearTalla(int $productoId, float $talla): int
    {
        // INSERT ... SELECT con CAST: así PostgreSQL sabe que el producto es un
        // integer (to_hex tiene versiones para integer y bigint) y la talla un NUMERIC(3,1)
        $consulta = BaseDatos::conexion()->prepare(
            "INSERT INTO variantes (producto_id, talla, stock, rfid_tienda)
             SELECT p.id, t.talla, 0,
                    '4B4C4D' || lpad(upper(to_hex(p.id)), 6, '0')
                             || lpad(upper(to_hex((t.talla * 10)::int)), 4, '0')
                             || '00000001'
             FROM productos p
             CROSS JOIN (SELECT CAST(:talla AS numeric(3,1)) AS talla) t
             WHERE p.id = :producto_id
             RETURNING id"
        );
        $consulta->execute(['talla' => (string) $talla, 'producto_id' => $productoId]);
        return (int) $consulta->fetchColumn();
    }

    /**
     * Cambia solo los campos indicados.
     *
     * @param array<string, mixed> $cambios columna => valor; solo se aceptan las de EDITABLES
     */
    public function actualizar(int $id, array $cambios): void
    {
        $cambios = array_intersect_key($cambios, array_flip(self::EDITABLES));
        if ($cambios === []) {
            return;
        }

        $asignaciones = [];
        $parametros = ['id' => $id];
        foreach ($cambios as $columna => $valor) {
            $asignaciones[] = "{$columna} = :{$columna}";   // nombre fijo de EDITABLES, nunca texto del usuario
            $parametros[$columna] = is_bool($valor) ? ($valor ? 'true' : 'false') : $valor;
        }

        BaseDatos::conexion()
            ->prepare('UPDATE productos SET ' . implode(', ', $asignaciones) . ' WHERE id = :id')
            ->execute($parametros);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function condiciones(array $filtros): array
    {
        $sql = ['true'];
        $parametros = [];

        if (isset($filtros['buscar'])) {
            // % y _ escritos por el usuario se buscan como texto, no como comodines
            $patron = '%' . addcslashes($filtros['buscar'], '%_\\') . '%';
            $sql[] = '(p.nombre ILIKE :b1 OR p.colorway ILIKE :b2 OR p.codigo_marca ILIKE :b3)';
            $parametros += ['b1' => $patron, 'b2' => $patron, 'b3' => $patron];
        }
        if (isset($filtros['activo'])) {
            $sql[] = 'p.activo = :activo';
            $parametros['activo'] = $filtros['activo'] ? 'true' : 'false';
        }

        return [implode(' AND ', $sql), $parametros];
    }
}
