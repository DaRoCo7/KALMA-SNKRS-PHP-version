<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Formato de salida que comparten todos los servicios.
 *
 * Existe para que la API escriba las fechas de una sola manera: si cada
 * servicio llevara su copia, bastaría con cambiar una para que dos pantallas
 * del panel recibieran la misma fecha con formatos distintos.
 */
final class Formato
{
    /**
     * Fecha de PostgreSQL (2026-11-08 19:15:00+01) en ISO 8601 (2026-11-08T19:15:00+01:00).
     * Un null se queda en null: muchas fechas son opcionales (revisado_en, ultima_venta...).
     */
    public static function fecha(?string $valor): ?string
    {
        return $valor === null ? null : (new \DateTimeImmutable($valor))->format(DATE_ATOM);
    }
}
