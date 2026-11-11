<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Configuración externa: todo se lee de variables de entorno (las pone
 * compose.yaml a partir del .env), nunca de valores escritos en el código.
 */
final class Entorno
{
    public static function obtener(string $nombre, ?string $porDefecto = null): ?string
    {
        $valor = getenv($nombre);
        return ($valor === false || $valor === '') ? $porDefecto : $valor;
    }

    /** @throws \RuntimeException si la variable no existe (el mensaje no incluye ningún valor) */
    public static function requerido(string $nombre): string
    {
        return self::obtener($nombre) ?? throw new \RuntimeException("Falta la variable de entorno {$nombre}");
    }

    /** APP_ENTORNO=desarrollo solo lo activa compose.dev.yaml; por defecto, producción */
    public static function esDesarrollo(): bool
    {
        return self::obtener('APP_ENTORNO', 'produccion') === 'desarrollo';
    }
}
