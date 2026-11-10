<?php

declare(strict_types=1);

namespace App\Nucleo;

use PDO;

/**
 * Acceso a PostgreSQL con PDO.
 *
 * - Una sola conexión por petición (se crea la primera vez que se pide).
 * - Consultas preparadas reales (EMULATE_PREPARES = false): los valores viajan
 *   separados del SQL, lo que impide la inyección SQL.
 * - Los datos de conexión salen de las variables de entorno.
 */
final class BaseDatos
{
    private static ?PDO $conexion = null;

    public static function conexion(): PDO
    {
        return self::$conexion ??= self::conectar(5);
    }

    /**
     * Ejecuta $operacion dentro de una transacción: si todo va bien hace COMMIT
     * y si se lanza cualquier excepción hace ROLLBACK y la relanza.
     *
     * @template T
     * @param callable(PDO): T $operacion
     * @return T
     */
    public static function transaccion(callable $operacion): mixed
    {
        $pdo = self::conexion();
        $pdo->beginTransaction();
        try {
            $resultado = $operacion($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Para /health: conexión nueva con tiempo de espera corto y SELECT 1 */
    public static function disponible(): bool
    {
        try {
            self::conectar(2)->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function conectar(int $segundosEspera): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=%d',
            Entorno::obtener('DB_HOST', 'db'),
            Entorno::obtener('DB_PORT', '5432'),
            Entorno::requerido('DB_NAME'),
            $segundosEspera
        );

        $pdo = new PDO($dsn, Entorno::requerido('DB_USER'), Entorno::requerido('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Las fechas (TIMESTAMPTZ) se devuelven en hora de España
        $pdo->exec("SET TIME ZONE 'Europe/Madrid'");

        return $pdo;
    }
}
