<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Consultas de solo lectura del panel resumen y de los informes del área interna.
 *
 * Todos los números se calculan aquí, en la BD, sobre los datos reales (JOIN +
 * GROUP BY): PHP solo les da formato. Cada informe es un método con su SQL
 * escrito en el código y las fechas como parámetros; el nombre del informe que
 * llega en la URL solo sirve para elegir el método (InformeServicio), nunca se
 * pega en el SQL.
 */
final class InformeRepositorio
{
    /**
     * Condición común a las ventas: sin pedidos cancelados (no son ventas: su
     * stock se repuso) y, si se indican, entre dos fechas. "hasta" es inclusiva:
     * se compara con el día siguiente a las 00:00 para incluir todo ese día.
     * Las fechas se interpretan en hora de España (zona de la conexión, BaseDatos).
     */
    private const FILTRO_VENTAS = "p.estado <> 'cancelado'
        AND (CAST(:desde AS date) IS NULL OR p.fecha_creacion >= CAST(:desde_filtro AS date))
        AND (CAST(:hasta AS date) IS NULL OR p.fecha_creacion < CAST(:hasta_filtro AS date) + 1)";

    // =========================================================================
    // Panel resumen
    // =========================================================================

    /** @return array<string, int> estado => número de pedidos (solo los estados que tienen alguno) */
    public function pedidosPorEstado(): array
    {
        $consulta = BaseDatos::conexion()->query('SELECT estado, count(*) AS pedidos FROM pedidos GROUP BY estado');
        return array_map('intval', $consulta->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /** @return array{unidades: int, ingresos: float, personalizados: int} de los pedidos no cancelados */
    public function ventas(): array
    {
        $fila = BaseDatos::conexion()->query(
            "SELECT COALESCE(sum(l.cantidad), 0) AS unidades,
                    COALESCE(sum(l.importe), 0)  AS ingresos,
                    (SELECT count(*) FROM pedidos
                     WHERE personalizado AND estado <> 'cancelado') AS personalizados
             FROM lineas_pedido l
             JOIN pedidos p ON p.id = l.pedido_id
             WHERE p.estado <> 'cancelado'"
        )->fetch();

        return [
            'unidades'       => (int) $fila['unidades'],
            'ingresos'       => (float) $fila['ingresos'],
            'personalizados' => (int) $fila['personalizados'],
        ];
    }

    /**
     * Tallas en el mínimo o por debajo (hay que reponerlas). Solo cuentan las
     * tallas activas de productos activos: lo que ya no se vende no se repone.
     */
    public function tallasBajoMinimo(): int
    {
        return (int) BaseDatos::conexion()->query(
            'SELECT count(*)
             FROM variantes v JOIN productos p ON p.id = v.producto_id
             WHERE v.activo AND p.activo AND v.stock <= v.stock_minimo'
        )->fetchColumn();
    }

    /** Productos activos con menos stock sumando todas sus tallas activas */
    public function modelosMenosStock(int $limite): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT p.id, p.nombre, p.colorway, p.codigo_marca, p.slug,
                    COALESCE(sum(v.stock), 0)                         AS stock_total,
                    count(v.id)                                       AS tallas,
                    count(v.id) FILTER (WHERE v.stock <= v.stock_minimo) AS tallas_bajo_minimo
             FROM productos p
             LEFT JOIN variantes v ON v.producto_id = p.id AND v.activo
             WHERE p.activo
             GROUP BY p.id
             ORDER BY stock_total, tallas_bajo_minimo DESC, p.nombre, p.id
             LIMIT :limite'
        );
        $consulta->execute(['limite' => $limite]);
        return $consulta->fetchAll();
    }

    // =========================================================================
    // Informes (siempre sin pedidos cancelados)
    // =========================================================================

    /** Ventas por producto (modelo + colorway), de más a menos ingresos */
    public function ventasPorModelo(?string $desde, ?string $hasta): array
    {
        return $this->ejecutar(
            'SELECT pr.id AS producto_id, pr.nombre, pr.colorway, pr.codigo_marca,
                    count(DISTINCT p.id) AS pedidos,
                    sum(l.cantidad)      AS unidades,
                    sum(l.importe)       AS ingresos
             FROM lineas_pedido l
             JOIN pedidos p    ON p.id = l.pedido_id
             JOIN variantes v  ON v.id = l.variante_id
             JOIN productos pr ON pr.id = v.producto_id
             WHERE ' . self::FILTRO_VENTAS . '
             GROUP BY pr.id, pr.nombre, pr.colorway, pr.codigo_marca
             ORDER BY ingresos DESC, unidades DESC, pr.nombre',
            $desde, $hasta
        );
    }

    /** Ventas por mes (AAAA-MM, en hora de España), del más antiguo al más reciente */
    public function ventasPorMes(?string $desde, ?string $hasta): array
    {
        return $this->ejecutar(
            "SELECT to_char(p.fecha_creacion, 'YYYY-MM') AS mes,
                    count(DISTINCT p.id) AS pedidos,
                    sum(l.cantidad)      AS unidades,
                    sum(l.importe)       AS ingresos
             FROM pedidos p
             JOIN lineas_pedido l ON l.pedido_id = p.id
             WHERE " . self::FILTRO_VENTAS . '
             GROUP BY mes
             ORDER BY mes',
            $desde, $hasta
        );
    }

    /**
     * Opciones de personalización vendidas: cuántas líneas la eligieron, para
     * cuántos pares y cuánto se ingresó por su recargo (el recargo es por par
     * y se usa el que se aplicó en el pedido, no el precio actual del catálogo).
     */
    public function personalizaciones(?string $desde, ?string $hasta): array
    {
        return $this->ejecutar(
            'SELECT o.id AS opcion_id, o.tipo, o.nombre,
                    count(*)                            AS veces,
                    sum(l.cantidad)                     AS pares,
                    sum(lp.recargo_aplicado * l.cantidad) AS ingresos_recargo
             FROM linea_personalizacion lp
             JOIN opciones_personalizacion o ON o.id = lp.opcion_id
             JOIN lineas_pedido l            ON l.id = lp.linea_id
             JOIN pedidos p                  ON p.id = l.pedido_id
             WHERE ' . self::FILTRO_VENTAS . '
             GROUP BY o.id, o.tipo, o.nombre
             ORDER BY ingresos_recargo DESC, veces DESC, o.nombre',
            $desde, $hasta
        );
    }

    /** Las dos fechas van dos veces porque cada marcador solo se puede usar una vez en la consulta */
    private function ejecutar(string $sql, ?string $desde, ?string $hasta): array
    {
        $consulta = BaseDatos::conexion()->prepare($sql);
        $consulta->execute([
            'desde' => $desde, 'desde_filtro' => $desde,
            'hasta' => $hasta, 'hasta_filtro' => $hasta,
        ]);
        return $consulta->fetchAll();
    }
}
