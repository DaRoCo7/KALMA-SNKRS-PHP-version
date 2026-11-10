<?php

declare(strict_types=1);

/*
 * Front controller: TODAS las peticiones entran por aquí (ver public/.htaccess).
 *
 *   petición -> Router -> [middlewares] -> Controlador -> Servicio -> Repositorio -> PostgreSQL
 *                                               └─> Respuesta (página HTML o JSON) -> navegador
 */

use App\Nucleo\ManejadorErrores;
use App\Nucleo\Peticion;
use App\Nucleo\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

ManejadorErrores::registrar();

$router = new Router();
require dirname(__DIR__) . '/config/rutas.php';

$peticion = Peticion::desdeGlobales();

try {
    $respuesta = $router->despachar($peticion);
} catch (Throwable $error) {
    // Cualquier error acaba en una respuesta controlada (HTML o JSON) sin trazas
    $respuesta = ManejadorErrores::aRespuesta($error, $peticion);
}

$respuesta->enviar();
