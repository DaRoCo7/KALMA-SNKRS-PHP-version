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
}
