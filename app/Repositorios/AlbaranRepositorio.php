<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Albaranes ya cargados, leídos de lo que deja AlbaranServicio::cargar() y sin
 * tabla propia (el archivo CSV no se guarda):
 *
 *   - el resumen de cada carga es su registro de auditoría «albaran.cargar»
 *     (usuario, fecha y el resumen en JSON);
 *   - las tallas que entraron son sus movimientos de stock de tipo «entrada»
 *     con el motivo «Albarán <referencia>».
 *
 * Un movimiento se une a SU carga por el motivo y además por la fecha: las dos
 * filas se escriben en la misma transacción y now() devuelve en PostgreSQL la
 * hora de inicio de la transacción, así que la fecha es idéntica. Con eso, si
 * alguien carga dos veces la misma referencia, cada carga ve solo sus tallas.
 */
final class AlbaranRepositorio
{
    private const DESDE = "FROM auditoria a
        LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.tipo_evento = 'albaran.cargar' AND a.resultado = 'exito'";

    private const COLUMNAS = 'a.id, a.fecha, a.detalle, a.usuario_id, u.nombre AS usuario_nombre,
        u.apellidos AS usuario_apellidos, u.email AS usuario_email';

    /** Cargas aceptadas, de la más reciente a la más antigua */
    public function listar(int $limite, int $desplazamiento): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ' ' . self::DESDE . '
             ORDER BY a.fecha DESC, a.id DESC
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute(['limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contar(): int
    {
        return (int) BaseDatos::conexion()->query('SELECT count(*) ' . self::DESDE)->fetchColumn();
    }

    /**
     * La carga más reciente de una referencia y cuántas veces se ha cargado
     * (la referencia la escribe el proveedor y nada impide repetirla).
     *
     * @return array|null con la columna extra «cargas»
     */
    public function buscarPorReferencia(string $referencia): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ', count(*) OVER () AS cargas ' . self::DESDE . "
               AND a.detalle->>'referencia' = :referencia
             ORDER BY a.fecha DESC, a.id DESC
             LIMIT 1"
        );
        $consulta->execute(['referencia' => $referencia]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Tallas que entraron con una carga: cantidad y stock que dejó cada entrada,
     * ordenadas por zapatilla y talla.
     */
    public function tallas(int $auditoriaId): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            "SELECT m.cantidad, m.stock_resultante, v.id AS variante_id, v.talla,
                    p.id AS producto_id, p.codigo_marca, p.nombre, p.colorway
             FROM auditoria a
             JOIN movimientos_stock m
               ON m.tipo = 'entrada'
              AND m.fecha = a.fecha
              AND m.motivo = 'Albarán ' || (a.detalle->>'referencia')
              AND m.usuario_id IS NOT DISTINCT FROM a.usuario_id
             JOIN variantes v ON v.id = m.variante_id
             JOIN productos p ON p.id = v.producto_id
             WHERE a.id = :id
             ORDER BY p.codigo_marca, v.talla"
        );
        $consulta->execute(['id' => $auditoriaId]);
        return $consulta->fetchAll();
    }
}
