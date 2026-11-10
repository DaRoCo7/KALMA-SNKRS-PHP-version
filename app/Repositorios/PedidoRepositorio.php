<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Acceso a datos de pedidos, sus líneas y su historial.
 * Solo SQL: las reglas (quién puede ver qué, qué estados se permiten) están
 * en PedidoServicio.
 */
final class PedidoRepositorio
{
    /** Datos del pedido y de su cliente, comunes al detalle y al listado */
    private const COLUMNAS = 'p.id, p.codigo, p.estado, p.total, p.direccion_envio,
        p.codigo_seguimiento, p.notas, p.fecha_creacion, p.fecha_actualizacion, p.cliente_id,
        u.nombre AS cliente_nombre, u.apellidos AS cliente_apellidos, u.email AS cliente_email';

    private const DESDE = 'FROM pedidos p
        JOIN clientes c ON c.id = p.cliente_id
        JOIN usuarios u ON u.id = c.usuario_id';

    /**
     * Crea la cabecera del pedido y le asigna su código visible (SF-2026-000025).
     *
     * El código se calcula a partir del id, que PostgreSQL genera al insertar.
     * Como la columna es NOT NULL UNIQUE, primero se guarda un código provisional
     * aleatorio y en la misma transacción se cambia por el definitivo.
     *
     * @return array{id: int, codigo: string}
     */
    public function crear(int $clienteId, string $direccionEnvio, ?string $notas): array
    {
        $pdo = BaseDatos::conexion();

        $insertar = $pdo->prepare(
            'INSERT INTO pedidos (codigo, cliente_id, direccion_envio, notas)
             VALUES (:provisional, :cliente_id, :direccion, :notas)
             RETURNING id'
        );
        $insertar->execute([
            'provisional' => 'TMP' . bin2hex(random_bytes(8)),   // 19 caracteres, cabe en VARCHAR(20)
            'cliente_id'  => $clienteId,
            'direccion'   => $direccionEnvio,
            'notas'       => $notas,
        ]);
        $id = (int) $insertar->fetchColumn();

        $codigo = $pdo->prepare(
            "UPDATE pedidos
             SET codigo = 'SF-' || to_char(fecha_creacion, 'YYYY') || '-'
                          -- 6 cifras con ceros a la izquierda; GREATEST evita que lpad recorte a partir del pedido 1.000.000
                          || lpad(id::text, GREATEST(6, length(id::text)), '0')
             WHERE id = :id
             RETURNING codigo"
        );
        $codigo->execute(['id' => $id]);

        return ['id' => $id, 'codigo' => (string) $codigo->fetchColumn()];
    }

    /**
     * Línea del pedido. Copia nombre, talla, color y precio del momento de la
     * compra: si luego cambia el catálogo, el pedido sigue diciendo lo que se vendió.
     * El importe lo calcula la propia BD (columna generada).
     */
    public function agregarLinea(int $pedidoId, array $linea): int
    {
        $consulta = BaseDatos::conexion()->prepare(
            'INSERT INTO lineas_pedido (pedido_id, variante_id, nombre_producto, talla, color, cantidad, precio_unitario)
             VALUES (:pedido_id, :variante_id, :nombre_producto, :talla, :color, :cantidad, :precio_unitario)
             RETURNING id'
        );
        $consulta->execute([
            'pedido_id'       => $pedidoId,
            'variante_id'     => $linea['variante_id'],
            'nombre_producto' => $linea['nombre_producto'],
            'talla'           => $linea['talla'],
            'color'           => $linea['color'],
            'cantidad'        => $linea['cantidad'],
            'precio_unitario' => $linea['precio_unitario'],
        ]);
        return (int) $consulta->fetchColumn();
    }

    /** El total es la suma de los importes de las líneas: lo calcula la BD, no PHP ni el navegador */
    public function actualizarTotal(int $pedidoId): float
    {
        $consulta = BaseDatos::conexion()->prepare(
            'UPDATE pedidos
             SET total = (SELECT COALESCE(sum(importe), 0) FROM lineas_pedido WHERE pedido_id = :id_lineas)
             WHERE id = :id
             RETURNING total'
        );
        $consulta->execute(['id_lineas' => $pedidoId, 'id' => $pedidoId]);
        return (float) $consulta->fetchColumn();
    }

    public function cambiarEstado(int $pedidoId, string $estado, ?string $codigoSeguimiento = null): void
    {
        BaseDatos::conexion()->prepare(
            'UPDATE pedidos
             SET estado = :estado, codigo_seguimiento = COALESCE(:seguimiento, codigo_seguimiento)
             WHERE id = :id'
        )->execute(['estado' => $estado, 'seguimiento' => $codigoSeguimiento, 'id' => $pedidoId]);
    }

    /** Cada transición queda en el historial: de qué estado a cuál, quién y cuándo */
    public function registrarEstado(int $pedidoId, ?string $anterior, string $nuevo, ?int $usuarioId, ?string $comentario): void
    {
        BaseDatos::conexion()->prepare(
            'INSERT INTO historial_estados_pedido (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario)
             VALUES (:pedido_id, :anterior, :nuevo, :usuario_id, :comentario)'
        )->execute([
            'pedido_id'  => $pedidoId,
            'anterior'   => $anterior,
            'nuevo'      => $nuevo,
            'usuario_id' => $usuarioId,
            'comentario' => $comentario,
        ]);
    }

    /**
     * Busca un pedido por su código.
     *
     * @param int|null $clienteId si se indica, solo lo encuentra si es de ese
     *                            cliente (protección IDOR: el pedido de otro
     *                            cliente "no existe" para él)
     * @param bool     $bloquear  FOR UPDATE: bloquea la fila hasta el final de la
     *                            transacción, para que dos peticiones no cambien
     *                            el estado del mismo pedido a la vez
     */
    public function buscarPorCodigo(string $codigo, ?int $clienteId = null, bool $bloquear = false): ?array
    {
        $sql = 'SELECT ' . self::COLUMNAS . ' ' . self::DESDE . '
                WHERE p.codigo = :codigo AND (CAST(:cliente AS integer) IS NULL OR p.cliente_id = :cliente_filtro)';
        if ($bloquear) {
            $sql .= ' FOR UPDATE OF p';
        }

        $consulta = BaseDatos::conexion()->prepare($sql);
        $consulta->execute(['codigo' => $codigo, 'cliente' => $clienteId, 'cliente_filtro' => $clienteId]);
        return $consulta->fetch() ?: null;
    }

    /** Líneas del pedido, con el slug y la imagen del producto para poder enlazarlo */
    public function lineas(int $pedidoId): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT l.id, l.variante_id, l.nombre_producto, l.color, l.talla, l.cantidad,
                    l.precio_unitario, l.importe, pr.slug, pr.imagen
             FROM lineas_pedido l
             JOIN variantes v  ON v.id = l.variante_id
             JOIN productos pr ON pr.id = v.producto_id
             WHERE l.pedido_id = :id
             ORDER BY l.id'
        );
        $consulta->execute(['id' => $pedidoId]);
        return $consulta->fetchAll();
    }

    public function historial(int $pedidoId): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT h.estado_anterior, h.estado_nuevo, h.comentario, h.fecha,
                    u.nombre AS usuario_nombre, r.codigo AS usuario_rol
             FROM historial_estados_pedido h
             LEFT JOIN usuarios u ON u.id = h.usuario_id
             LEFT JOIN roles r    ON r.id = u.rol_id
             WHERE h.pedido_id = :id
             ORDER BY h.fecha, h.id'
        );
        $consulta->execute(['id' => $pedidoId]);
        return $consulta->fetchAll();
    }

    /**
     * Listado paginado, del más reciente al más antiguo.
     *
     * @param array{cliente_id?: int, estado?: string, buscar?: string} $filtros
     */
    public function listar(array $filtros, int $limite, int $desplazamiento): array
    {
        [$where, $parametros] = $this->condiciones($filtros);

        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ', l.num_lineas, l.unidades ' . self::DESDE . '
             CROSS JOIN LATERAL (
                 SELECT count(*) AS num_lineas, COALESCE(sum(cantidad), 0) AS unidades
                 FROM lineas_pedido WHERE pedido_id = p.id
             ) l
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
        $consulta = BaseDatos::conexion()->prepare('SELECT count(*) ' . self::DESDE . ' WHERE ' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function condiciones(array $filtros): array
    {
        $sql = ['true'];
        $parametros = [];

        if (isset($filtros['cliente_id'])) {
            $sql[] = 'p.cliente_id = :cliente_id';
            $parametros['cliente_id'] = $filtros['cliente_id'];
        }
        if (isset($filtros['estado'])) {
            $sql[] = 'p.estado = :estado';
            $parametros['estado'] = $filtros['estado'];
        }
        if (isset($filtros['buscar'])) {
            // Por código de pedido o por email del cliente. % y _ se buscan como texto
            $patron = '%' . addcslashes($filtros['buscar'], '%_\\') . '%';
            $sql[] = '(p.codigo ILIKE :buscar_codigo OR u.email ILIKE :buscar_email)';
            $parametros += ['buscar_codigo' => $patron, 'buscar_email' => $patron];
        }

        return [implode(' AND ', $sql), $parametros];
    }
}
