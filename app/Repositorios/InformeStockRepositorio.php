<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Informes de stock del almacén: las dos consultas que los calculan y su
 * guardado, que vive en la tabla auditoria (no hay tabla propia).
 *
 * Por qué en auditoria: un informe enviado es un hecho que no cambia (quién lo
 * envió, cuándo y qué filas había en ese momento) y revisarlo es otro hecho.
 * Es justo lo que guarda la auditoría, igual que con los albaranes:
 *   - Enviar   = una fila tipo_evento 'informe_stock.enviar', entidad
 *                'informe_stock'. Su id ES el id del informe y su detalle
 *                (JSONB) lleva el tipo, los parámetros, el comentario y la
 *                FOTO de las filas, para que el informe no cambie después.
 *   - Revisar  = otra fila 'informe_stock.revisar' con entidad_id = id del
 *                informe. La primera que haya es la que cuenta.
 *   - Pendiente = un envío sin ninguna revisión.
 * Las dos filas comparten la entidad 'informe_stock', así que buscar la
 * revisión de un informe usa el índice ix_auditoria_entidad (entidad, entidad_id).
 *
 * Todo con consultas preparadas: ni el tipo ni los filtros se pegan en el SQL.
 */
final class InformeStockRepositorio
{
    public const EVENTO_ENVIAR = 'informe_stock.enviar';
    public const EVENTO_REVISAR = 'informe_stock.revisar';
    public const ENTIDAD = 'informe_stock';

    /**
     * Solo lo que se vende hoy: producto y talla activos y ya lanzado. Un
     * lanzamiento anunciado no tiene stock que reponer ni ventas que esperar.
     */
    private const A_LA_VENTA = 'p.activo AND (p.fecha_lanzamiento IS NULL OR p.fecha_lanzamiento <= CURRENT_DATE)';

    // =========================================================================
    // Cálculo de los informes
    // =========================================================================

    /**
     * Tallas en su mínimo o por debajo: el mismo umbral que el resumen y el
     * control de stock (variantes.stock_minimo de cada talla). Primero las
     * agotadas y las que más lejos están de su mínimo.
     *
     * @param int $limite filas como máximo (el servicio pide una más para saber si recorta)
     */
    public function stockBajo(int $limite): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT v.id AS variante_id, p.id AS producto_id, p.nombre, p.colorway, p.codigo_marca,
                    v.talla, v.stock, v.stock_minimo, v.rfid_tienda
             FROM variantes v
             JOIN productos p ON p.id = v.producto_id
             WHERE v.activo AND ' . self::A_LA_VENTA . ' AND v.stock <= v.stock_minimo
             ORDER BY v.stock, v.stock - v.stock_minimo, p.nombre, p.colorway, v.talla
             LIMIT :limite'
        );
        $consulta->execute(['limite' => $limite]);
        return $consulta->fetchAll();
    }

    /** Número total de tallas con stock bajo (aunque la foto se recorte) */
    public function contarStockBajo(): int
    {
        return (int) BaseDatos::conexion()->query(
            'SELECT count(*) FROM variantes v JOIN productos p ON p.id = v.producto_id
             WHERE v.activo AND ' . self::A_LA_VENTA . ' AND v.stock <= v.stock_minimo'
        )->fetchColumn();
    }

    /**
     * Zapatillas a la venta cuya última venta (línea de un pedido no
     * cancelado: un cancelado no es una venta, su stock se repuso) es de hace
     * más de $dias días, o que no se han vendido nunca. Primero las que nunca
     * se han vendido y después de la venta más antigua a la más reciente.
     *
     * dias_sin_vender se cuenta en días naturales de la hora de España (la
     * zona de la conexión): una venta de ayer a las 23:59 es 1 día.
     */
    public function sinVentas(int $dias, int $limite): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            $this->sqlSinVentas() . '
             ORDER BY u.ultima_venta NULLS FIRST, p.nombre, p.colorway, p.id
             LIMIT :limite'
        );
        $consulta->execute(['dias' => $dias, 'limite' => $limite]);
        return $consulta->fetchAll();
    }

    public function contarSinVentas(int $dias): int
    {
        $consulta = BaseDatos::conexion()->prepare('SELECT count(*) FROM (' . $this->sqlSinVentas() . ') t');
        $consulta->execute(['dias' => $dias]);
        return (int) $consulta->fetchColumn();
    }

    private function sqlSinVentas(): string
    {
        return 'WITH ultimas AS (
                    SELECT v.producto_id, max(pe.fecha_creacion) AS ultima_venta
                    FROM lineas_pedido l
                    JOIN pedidos pe  ON pe.id = l.pedido_id AND pe.estado <> \'cancelado\'
                    JOIN variantes v ON v.id = l.variante_id
                    GROUP BY v.producto_id
                ),
                existencias AS (
                    SELECT producto_id, sum(stock) AS stock_total, count(*) AS tallas
                    FROM variantes WHERE activo GROUP BY producto_id
                )
                SELECT p.id AS producto_id, p.nombre, p.colorway, p.codigo_marca,
                       COALESCE(e.stock_total, 0) AS stock_total,
                       COALESCE(e.tallas, 0)      AS tallas,
                       u.ultima_venta,
                       CURRENT_DATE - CAST(u.ultima_venta AS date) AS dias_sin_vender
                FROM productos p
                LEFT JOIN ultimas u     ON u.producto_id = p.id
                LEFT JOIN existencias e ON e.producto_id = p.id
                WHERE ' . self::A_LA_VENTA . '
                  AND (u.ultima_venta IS NULL
                       OR u.ultima_venta < now() - make_interval(days => CAST(:dias AS integer)))';
    }

    // =========================================================================
    // Guardado en la auditoría
    // =========================================================================

    /**
     * Guarda el envío de un informe y devuelve su id (el de la fila de auditoría).
     *
     * @return array{id: int, fecha: string}
     */
    public function guardarEnvio(int $usuarioId, array $detalle, ?string $ip): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            "INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, ip)
             VALUES (:usuario_id, :tipo_evento, :entidad, NULL, 'exito', CAST(:detalle AS jsonb), CAST(:ip AS inet))
             RETURNING id, fecha"
        );
        $consulta->execute([
            'usuario_id'  => $usuarioId,
            'tipo_evento' => self::EVENTO_ENVIAR,
            'entidad'     => self::ENTIDAD,
            'detalle'     => json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'ip'          => $ip,
        ]);
        $fila = $consulta->fetch();
        return ['id' => (int) $fila['id'], 'fecha' => $fila['fecha']];
    }

    /**
     * Informes enviados, los pendientes primero y dentro de cada grupo del más
     * reciente al más antiguo. Sin las filas de la foto: el listado solo
     * necesita cuántas son.
     *
     * @param array{creado_por?: int, estado?: string, tipo?: string} $filtros
     */
    public function listar(array $filtros, int $limite, int $desplazamiento): array
    {
        [$where, $parametros] = $this->condiciones($filtros);

        $consulta = BaseDatos::conexion()->prepare(
            $this->sqlInforme(false) . '
             WHERE ' . $where . '
             ORDER BY (r.fecha IS NULL) DESC, e.fecha DESC, e.id DESC
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute($parametros + ['limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contar(array $filtros): int
    {
        [$where, $parametros] = $this->condiciones($filtros);
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT count(*) FROM auditoria e
             LEFT JOIN LATERAL (' . self::SQL_REVISION . ') r ON true
             WHERE ' . $where
        );
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /**
     * Un informe con su foto completa, o null si no existe.
     *
     * @param bool $bloquear FOR UPDATE de la fila del envío: dos administradores
     *                       que lo revisan a la vez no dejan dos revisiones
     */
    public function buscar(int $id, bool $bloquear = false): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            $this->sqlInforme(true) . '
             WHERE e.id = :id AND e.entidad = :entidad AND e.tipo_evento = :evento'
            . ($bloquear ? ' FOR UPDATE OF e' : '')
        );
        $consulta->execute(['id' => $id, 'entidad' => self::ENTIDAD, 'evento' => self::EVENTO_ENVIAR]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Revisión de un informe, o null si está pendiente. Va en una consulta
     * aparte de buscar(): tras esperar al FOR UPDATE, una consulta NUEVA ve lo
     * que otro administrador acaba de confirmar; la subconsulta de buscar()
     * seguiría con lo que había antes de la espera.
     */
    public function revision(int $id): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT r.fecha, r.usuario_id FROM (SELECT CAST(:id AS bigint) AS id) e
             CROSS JOIN LATERAL (' . self::SQL_REVISION . ') r'
        );
        $consulta->execute(['id' => $id]);
        return $consulta->fetch() ?: null;
    }

    /** La primera revisión de cada informe: si por lo que sea hubiera dos, cuenta la primera */
    private const SQL_REVISION = "SELECT a.fecha, a.usuario_id
        FROM auditoria a
        WHERE a.entidad = 'informe_stock' AND a.entidad_id = e.id AND a.tipo_evento = 'informe_stock.revisar'
        ORDER BY a.fecha, a.id
        LIMIT 1";

    /** Datos de un informe; la foto (detalle->filas) solo en el detalle */
    private function sqlInforme(bool $conFilas): string
    {
        return "SELECT e.id, e.fecha AS creado_en,
                       e.detalle->>'tipo'       AS tipo,
                       e.detalle->'parametros'  AS parametros,
                       e.detalle->>'comentario' AS comentario,
                       e.detalle->>'generado_en' AS generado_en,
                       CAST(e.detalle->>'total_filas' AS integer) AS total_filas,
                       COALESCE(CAST(e.detalle->>'recortado' AS boolean), false) AS recortado,
                       " . ($conFilas ? "e.detalle->'filas'" : 'NULL') . " AS filas,
                       e.usuario_id AS creado_por_id, uc.nombre AS creado_por_nombre,
                       uc.apellidos AS creado_por_apellidos, uc.email AS creado_por_email,
                       r.fecha AS revisado_en,
                       r.usuario_id AS revisado_por_id, ur.nombre AS revisado_por_nombre,
                       ur.apellidos AS revisado_por_apellidos, ur.email AS revisado_por_email
                FROM auditoria e
                LEFT JOIN usuarios uc ON uc.id = e.usuario_id
                LEFT JOIN LATERAL (" . self::SQL_REVISION . ") r ON true
                LEFT JOIN usuarios ur ON ur.id = r.usuario_id";
    }

    /**
     * WHERE del listado. Cada condición es texto fijo de este método y el valor
     * va siempre como parámetro.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function condiciones(array $filtros): array
    {
        $sql = ['e.entidad = :entidad', 'e.tipo_evento = :evento'];
        $parametros = ['entidad' => self::ENTIDAD, 'evento' => self::EVENTO_ENVIAR];

        if (isset($filtros['creado_por'])) {
            $sql[] = 'e.usuario_id = :creado_por';
            $parametros['creado_por'] = $filtros['creado_por'];
        }
        if (isset($filtros['tipo'])) {
            $sql[] = "e.detalle->>'tipo' = :tipo";
            $parametros['tipo'] = $filtros['tipo'];
        }
        if (($filtros['estado'] ?? null) === 'pendiente') {
            $sql[] = 'r.fecha IS NULL';
        } elseif (($filtros['estado'] ?? null) === 'revisado') {
            $sql[] = 'r.fecha IS NOT NULL';
        }

        return [implode(' AND ', $sql), $parametros];
    }
}
