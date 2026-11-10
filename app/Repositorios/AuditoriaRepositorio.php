<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Registro de eventos de negocio (tabla auditoria): quién, cuándo, qué tipo
 * de evento, sobre qué entidad y con qué resultado.
 *
 * En $detalle NUNCA van contraseñas, hashes ni tokens.
 */
final class AuditoriaRepositorio
{
    /**
     * @param string $resultado exito | error | denegado
     */
    public function registrar(
        ?int $usuarioId,
        string $tipoEvento,
        string $entidad,
        ?int $entidadId,
        string $resultado,
        array $detalle = [],
        ?string $ip = null,
    ): void {
        $sql = <<<SQL
            INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, ip)
            VALUES (:usuario_id, :tipo_evento, :entidad, :entidad_id, :resultado,
                    CAST(:detalle AS jsonb), CAST(:ip AS inet))
            SQL;

        BaseDatos::conexion()->prepare($sql)->execute([
            'usuario_id'  => $usuarioId,
            'tipo_evento' => $tipoEvento,
            'entidad'     => $entidad,
            'entidad_id'  => $entidadId,
            'resultado'   => $resultado,
            'detalle'     => $detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
            'ip'          => $ip,
        ]);
    }

    /**
     * Registro paginado, del evento más reciente al más antiguo, con el email
     * del usuario (LEFT JOIN: los eventos anónimos o de usuarios borrados
     * también salen, con email null).
     *
     * @param array{tipo?: string, entidad?: string, resultado?: string, usuario_id?: int} $filtros
     */
    public function listar(array $filtros, int $limite, int $desplazamiento): array
    {
        [$where, $parametros] = $this->condiciones($filtros);

        $consulta = BaseDatos::conexion()->prepare(
            'SELECT a.id, a.fecha, a.usuario_id, u.email AS usuario_email, a.tipo_evento, a.entidad,
                    a.entidad_id, a.resultado, a.detalle, host(a.ip) AS ip
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             WHERE ' . $where . '
             ORDER BY a.fecha DESC, a.id DESC
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute($parametros + ['limite' => $limite, 'desplazamiento' => $desplazamiento]);
        return $consulta->fetchAll();
    }

    public function contar(array $filtros): int
    {
        [$where, $parametros] = $this->condiciones($filtros);
        $consulta = BaseDatos::conexion()->prepare('SELECT count(*) FROM auditoria a WHERE ' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /**
     * WHERE con los filtros que lleguen. Cada condición es texto fijo de este
     * método y el valor va siempre como parámetro.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function condiciones(array $filtros): array
    {
        $sql = ['true'];
        $parametros = [];

        if (isset($filtros['tipo'])) {
            $sql[] = 'a.tipo_evento = :tipo';
            $parametros['tipo'] = $filtros['tipo'];
        }
        if (isset($filtros['entidad'])) {
            $sql[] = 'a.entidad = :entidad';
            $parametros['entidad'] = $filtros['entidad'];
        }
        if (isset($filtros['resultado'])) {
            $sql[] = 'a.resultado = :resultado';
            $parametros['resultado'] = $filtros['resultado'];
        }
        if (isset($filtros['usuario_id'])) {
            $sql[] = 'a.usuario_id = :usuario_id';
            $parametros['usuario_id'] = $filtros['usuario_id'];
        }

        return [implode(' AND ', $sql), $parametros];
    }
}
