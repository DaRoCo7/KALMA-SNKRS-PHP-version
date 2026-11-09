
<?php

declare(strict_types=1);

/**
 * Devuelve una conexión PDO con PostgreSQL.
 */
function obtenerConexion(): PDO
{
    $host = getenv('DB_HOST') ?: 'db';
    $puerto = getenv('DB_PORT') ?: '5432';
    $nombre = getenv('DB_NAME');
    $usuario = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');

    // Comprobar que las variables necesarias existen.
    if (!$nombre || !$usuario || !$password) {
        throw new RuntimeException(
            'Faltan variables de configuración de la base de datos.'
        );
    }

    $dsn = "pgsql:host={$host};port={$puerto};dbname={$nombre}";

    return new PDO($dsn, $usuario, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
}
