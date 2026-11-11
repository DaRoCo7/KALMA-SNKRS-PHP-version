<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Stock de las tallas (tabla variantes) y su rastro (tabla movimientos_stock).
 *
 * Regla: el stock NUNCA se cambia con un UPDATE suelto. Siempre pasa por
 * mover(), que actualiza la talla y deja el movimiento en la misma consulta.
 * Así cada unidad que entra o sale tiene su motivo, su pedido y su usuario.
 */
final class StockRepositorio
{
    /**
     * Datos de una talla en el área interna. bajo_minimo lo calcula la BD: la
     * talla está en o por debajo del mínimo y hay que reponerla.
     */
    private const COLUMNAS_TALLA = 'v.id AS variante_id, v.talla, v.stock, v.stock_minimo,
        (v.stock <= v.stock_minimo) AS bajo_minimo, v.rfid_tienda, v.activo,
        p.id AS producto_id, p.nombre, p.colorway, p.codigo_marca, p.slug, p.activo AS producto_activo';

    private const DESDE_TALLAS = 'FROM variantes v JOIN productos p ON p.id = v.producto_id';

    /**
     * Tallas para el control de stock, ordenadas por modelo, colorway y talla.
     *
     * @param array{buscar?: string, bajo_minimo?: bool, producto_id?: int} $filtros
     */
    public function listarTallas(array $filtros, int $limite, int $desplazamiento): array
    {
        [$where, $parametros] = $this->condicionesTallas($filtros);

        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS_TALLA . ' ' . self::DESDE_TALLAS . '
             WHERE ' . $where . '
             ORDER BY p.nombre, p.colorway, v.talla
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute($parametros + ['limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contarTallas(array $filtros): int
    {
        [$where, $parametros] = $this->condicionesTallas($filtros);
        $consulta = BaseDatos::conexion()->prepare('SELECT count(*) ' . self::DESDE_TALLAS . ' WHERE ' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /** Una talla con los datos de su producto, o null si no existe */
    public function buscarTalla(int $varianteId): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS_TALLA . ' ' . self::DESDE_TALLAS . ' WHERE v.id = :id'
        );
        $consulta->execute(['id' => $varianteId]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Historial de movimientos de una talla, del más reciente al más antiguo,
     * con el código del pedido y el usuario que lo provocó (si los hay).
     */
    public function movimientos(int $varianteId, int $limite, int $desplazamiento): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT m.id, m.tipo, m.cantidad, m.stock_resultante, m.motivo, m.fecha,
                    pe.codigo AS pedido_codigo,
                    u.id AS usuario_id, u.nombre AS usuario_nombre, u.apellidos AS usuario_apellidos,
                    r.codigo AS usuario_rol
             FROM movimientos_stock m
             LEFT JOIN pedidos pe ON pe.id = m.pedido_id
             LEFT JOIN usuarios u ON u.id = m.usuario_id
             LEFT JOIN roles r    ON r.id = u.rol_id
             WHERE m.variante_id = :id
             ORDER BY m.fecha DESC, m.id DESC
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute(['id' => $varianteId, 'limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contarMovimientos(int $varianteId): int
    {
        $consulta = BaseDatos::conexion()->prepare('SELECT count(*) FROM movimientos_stock WHERE variante_id = :id');
        $consulta->execute(['id' => $varianteId]);
        return (int) $consulta->fetchColumn();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function condicionesTallas(array $filtros): array
    {
        $sql = ['true'];
        $parametros = [];

        if (isset($filtros['buscar'])) {
            // Por nombre del modelo o por su código de Nike (CW2288-111). % y _ se buscan como texto.
            // También por la etiqueta RFID completa: en el almacén se lee la etiqueta con la pistola.
            $patron = '%' . addcslashes($filtros['buscar'], '%_\\') . '%';
            $sql[] = '(p.nombre ILIKE :buscar_nombre OR p.codigo_marca ILIKE :buscar_codigo OR v.rfid_tienda = upper(:buscar_rfid))';
            $parametros += ['buscar_nombre' => $patron, 'buscar_codigo' => $patron, 'buscar_rfid' => $filtros['buscar']];
        }
        if (!empty($filtros['bajo_minimo'])) {
            // Un lanzamiento sin stock no es una talla agotada: aún no ha llegado
            $sql[] = 'v.stock <= v.stock_minimo AND (p.fecha_lanzamiento IS NULL OR p.fecha_lanzamiento <= CURRENT_DATE)';
        }
        if (isset($filtros['producto_id'])) {
            $sql[] = 'v.producto_id = :producto_id';
            $parametros['producto_id'] = $filtros['producto_id'];
        }

        return [implode(' AND ', $sql), $parametros];
    }

    /**
     * Lee y BLOQUEA las tallas indicadas hasta el final de la transacción.
     *
     * SELECT ... FOR UPDATE: si dos clientes compran la misma talla a la vez,
     * el segundo espera aquí a que el primero termine (COMMIT o ROLLBACK) y
     * entonces lee el stock ya descontado. Sin esto, los dos verían "queda 1"
     * y venderían la misma unidad dos veces.
     *
     * Se bloquean en orden de id para que dos pedidos con las mismas tallas en
     * distinto orden no se queden esperándose el uno al otro (interbloqueo).
     *
     * Debe llamarse dentro de BaseDatos::transaccion().
     *
     * @param list<int> $ids
     * @return array<int, array> tallas indexadas por id (las que no existen no aparecen)
     */
    public function bloquearVariantes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $consulta = BaseDatos::conexion()->prepare(
            'SELECT v.id, v.producto_id, v.talla, v.stock, v.activo,
                    p.nombre, p.colorway, p.precio, p.personalizable, p.activo AS producto_activo,
                    -- Un lanzamiento anunciado no se vende hasta su fecha, aunque ya haya llegado stock
                    (p.fecha_lanzamiento IS NULL OR p.fecha_lanzamiento <= CURRENT_DATE) AS lanzado
             FROM variantes v
             JOIN productos p ON p.id = v.producto_id
             WHERE v.id = ANY(CAST(:ids AS integer[]))
             ORDER BY v.id
             FOR UPDATE OF v'
        );
        // Array de PostgreSQL en texto: {3,7,12}. Los ids ya llegan validados como enteros.
        $consulta->execute(['ids' => '{' . implode(',', array_map('intval', $ids)) . '}']);

        $variantes = [];
        foreach ($consulta->fetchAll() as $fila) {
            $variantes[(int) $fila['id']] = $fila;
        }
        return $variantes;
    }

    /**
     * Suma (entrada, cancelación) o resta (venta) unidades a una talla y deja
     * el movimiento registrado. Si el stock quedara negativo, el CHECK de la
     * tabla lo impide y PostgreSQL lanza un error (nunca se vende lo que no hay).
     *
     * @param string $tipo entrada | venta | cancelacion | ajuste
     * @return int stock que queda después del movimiento
     */
    public function mover(
        int $varianteId,
        int $cantidad,
        string $tipo,
        ?int $pedidoId,
        ?int $usuarioId,
        ?string $motivo,
    ): int {
        // Una sola consulta: el UPDATE devuelve el stock nuevo y el INSERT lo guarda en el movimiento.
        // Los CAST son necesarios: en un INSERT ... SELECT PostgreSQL no deduce el tipo de
        // los parámetros a partir de la columna de destino.
        $sql = <<<SQL
            WITH talla AS (
                UPDATE variantes SET stock = stock + :cantidad
                WHERE id = :variante_id
                RETURNING id, stock
            )
            INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_resultante, pedido_id, usuario_id, motivo)
            SELECT id, CAST(:tipo AS varchar), CAST(:cantidad_movimiento AS integer), stock,
                   CAST(:pedido_id AS integer), CAST(:usuario_id AS integer), CAST(:motivo AS varchar)
            FROM talla
            RETURNING stock_resultante
            SQL;

        $consulta = BaseDatos::conexion()->prepare($sql);
        $consulta->execute([
            'cantidad'            => $cantidad,
            'variante_id'         => $varianteId,
            'tipo'                => $tipo,
            'cantidad_movimiento' => $cantidad,
            'pedido_id'           => $pedidoId,
            'usuario_id'          => $usuarioId,
            'motivo'              => $motivo,
        ]);

        return (int) $consulta->fetchColumn();
    }
}
