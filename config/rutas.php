<?php

declare(strict_types=1);

/*
 * Tabla de rutas de la aplicación.
 *
 *   - Páginas HTML (las genera el servidor): /catalogo, /login, /panel...
 *   - API JSON: todo lo que empieza por /api
 *   - Sistema: /health (contrato con Despliegue)
 *
 * Formato: $router->metodo('/ruta/{parametro}', [Controlador::class, 'metodo'], [middlewares]);
 */

use App\Controladores\Api\InicioController;
use App\Controladores\SaludController;
use App\Controladores\Web\CatalogoController;

/** @var \App\Nucleo\Router $router */

// --- Sistema ------------------------------------------------------------------
$router->get('/health', [SaludController::class, 'comprobar']);

// --- Páginas HTML --------------------------------------------------------------
$router->get('/', [CatalogoController::class, 'index']);
$router->get('/catalogo', [CatalogoController::class, 'index']);

// --- API JSON ------------------------------------------------------------------
$router->get('/api', [InicioController::class, 'index']);
