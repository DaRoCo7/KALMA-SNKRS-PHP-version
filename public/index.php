<?php

$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($ruta) {
    case '/':
    case '/catalogo':
    case '/catalogo/':
        require __DIR__ . '/../app/vistas/catalogo.php';
        break;

    default:
        http_response_code(404);
        require __DIR__ . '/../app/vistas/404.php';
}