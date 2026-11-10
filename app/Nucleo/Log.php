<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Log de la aplicación.
 *
 * Escribe una línea JSON por evento en la salida de errores del contenedor, así
 * que se consulta con `docker compose logs app`. Cada línea lleva fecha con zona
 * horaria y nivel.
 *
 * NUNCA se deben pasar contraseñas, tokens ni datos personales en $contexto.
 */
final class Log
{
    public static function info(string $mensaje, array $contexto = []): void
    {
        self::escribir('INFO', $mensaje, $contexto);
    }

    public static function aviso(string $mensaje, array $contexto = []): void
    {
        self::escribir('AVISO', $mensaje, $contexto);
    }

    public static function error(string $mensaje, array $contexto = []): void
    {
        self::escribir('ERROR', $mensaje, $contexto);
    }

    private static function escribir(string $nivel, string $mensaje, array $contexto): void
    {
        $linea = json_encode(
            ['fecha' => date(DATE_ATOM), 'nivel' => $nivel, 'mensaje' => $mensaje] + $contexto,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        file_put_contents('php://stderr', $linea . PHP_EOL);
    }
}
