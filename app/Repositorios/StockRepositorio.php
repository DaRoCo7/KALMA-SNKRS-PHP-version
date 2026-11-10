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
                    p.nombre, p.colorway, p.precio, p.personalizable, p.activo AS producto_activo
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
