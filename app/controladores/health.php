<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$host = getenv('DB_HOST');
$nombre = getenv('DB_NAME');
$usuario = getenv('DB_USER');
$puerto = getenv('DB_PORT') ?: '5432';
$estadoBD = 'sin_configurar';

if ($host !== false && $host !== '' &&
    $nombre !== false && $nombre !== '' &&
    $usuario !== false && $usuario !== '') {
    $estadoBD = 'no_disponible';

    try {
        $dsn = "pgsql:host={$host};port={$puerto};dbname={$nombre};connect_timeout=2";
        $conexion = new PDO($dsn, $usuario, getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $conexion->query('SELECT 1');
        $estadoBD = 'disponible';
    } catch (PDOException $error) {
        // La respuesta pública solo indica el estado de la conexión.
    }
}

http_response_code($estadoBD === 'disponible' ? 200 : 503);
echo json_encode(['app' => 'disponible', 'base_datos' => $estadoBD]);