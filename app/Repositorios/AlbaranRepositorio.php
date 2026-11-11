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
 *     con el motivo «Albarán <referencia>»;
 *   - los intentos de cargar una referencia ya cargada que se rechazaron son
 *     registros «albaran.repetido_rechazado»: los avisos de administración.
 *
 * Un movimiento se une a SU carga por el motivo y además por la fecha: las dos
 * filas se escriben en la misma transacción y now() devuelve en PostgreSQL la
 * hora de inicio de la transacción, así que la fecha es idéntica. Con eso, si
 * la administración acepta cargar dos veces la misma referencia, cada carga ve
 * solo sus tallas.
 */
final class AlbaranRepositorio
{
    public const EVENTO_CARGA = 'albaran.cargar';
    public const EVENTO_RECHAZO = 'albaran.repetido_rechazado';

    private const DESDE = "FROM auditoria a
        LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.tipo_evento = 'albaran.cargar' AND a.resultado = 'exito'";

    private const COLUMNAS = 'a.id, a.fecha, a.detalle, a.usuario_id, u.nombre AS usuario_nombre,
        u.apellidos AS usuario_apellidos, u.email AS usuario_email';

    /**
     * Cerrojo de la referencia hasta el final de la transacción. Sin él, dos
     * envíos simultáneos de la misma referencia comprobarían a la vez que no
     * estaba cargada y entrarían los dos. Es un cerrojo «advisory» porque no
     * hay fila de albarán que bloquear; hashtext() da un entero por referencia
     * (si dos referencias distintas coinciden en el hash, solo se esperan).
     *
     * Debe llamarse dentro de BaseDatos::transaccion().
     */
    public function bloquearReferencia(string $referencia): void
    {
        BaseDatos::conexion()
            ->prepare('SELECT pg_advisory_xact_lock(hashtext(:referencia))')
            ->execute(['referencia' => $referencia]);
    }

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
     * (solo puede haber más de una si la administración aceptó repetirla).
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

    /**
     * Intentos rechazados de cargar una referencia que ya estaba cargada, del
     * más reciente al más antiguo. «aceptada_despues» dice si después se
     * aceptó cargarla igualmente: así el aviso se puede dar por resuelto.
     */
    public function avisos(int $limite, int $desplazamiento): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            "SELECT a.id, a.fecha, a.detalle, a.usuario_id, u.nombre AS usuario_nombre,
                    u.apellidos AS usuario_apellidos, u.email AS usuario_email,
                    EXISTS (
                        SELECT 1 FROM auditoria c
                        WHERE c.tipo_evento = 'albaran.cargar' AND c.resultado = 'exito'
                          AND c.detalle->>'referencia' = a.detalle->>'referencia'
                          AND c.fecha > a.fecha
                    ) AS aceptada_despues
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             WHERE a.tipo_evento = 'albaran.repetido_rechazado'
             ORDER BY a.fecha DESC, a.id DESC
             LIMIT :limite OFFSET :desplazamiento"
        );
        $consulta->execute(['limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contarAvisos(): int
    {
        return (int) BaseDatos::conexion()
            ->query("SELECT count(*) FROM auditoria WHERE tipo_evento = 'albaran.repetido_rechazado'")
            ->fetchColumn();
    }
}
