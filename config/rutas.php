<?php

declare(strict_types=1);

/*
 * Tabla de rutas de la aplicación.
 *
 *   - Páginas HTML (las genera el servidor): /catalogo, /login, /registro...
 *   - API JSON: todo lo que empieza por /api
 *   - Sistema: /health (contrato con Despliegue)
 *
 * Formato: $router->metodo('/ruta/{parametro}', [Controlador::class, 'metodo'], [middlewares]);
 * Los permisos de cada rol están en App\Seguridad\Permisos (matriz de permisos).
 */

use App\Controladores\Api\AuthController as ApiAuthController;
use App\Controladores\Api\InicioController;
use App\Controladores\Api\UsuarioController;
use App\Controladores\SaludController;
use App\Controladores\Web\AuthController as WebAuthController;
use App\Controladores\Web\CatalogoController;
use App\Middlewares\Autenticado;
use App\Middlewares\IniciarSesion;
use App\Middlewares\LimitarTasa;
use App\Middlewares\RequierePermiso;
use App\Middlewares\VerificarCsrf;

/** @var \App\Nucleo\Router $router */

// --- Middlewares comunes a todas las rutas (en este orden) ----------------------
$router->usar(new IniciarSesion());
$router->usar(new VerificarCsrf());

// Rate limiting por IP en la aplicación (además del de Nginx)
$limiteLogin    = new LimitarTasa('login', 20, 900);      // 20 intentos por IP cada 15 min
$limiteRegistro = new LimitarTasa('registro', 5, 3600);   // 5 registros por IP y hora

// --- Sistema ------------------------------------------------------------------
$router->get('/health', [SaludController::class, 'comprobar']);

// --- Páginas HTML --------------------------------------------------------------
$router->get('/', [CatalogoController::class, 'index']);
$router->get('/catalogo', [CatalogoController::class, 'index']);

$router->get('/login', [WebAuthController::class, 'formularioLogin']);
$router->post('/login', [WebAuthController::class, 'login'], [$limiteLogin]);
$router->get('/registro', [WebAuthController::class, 'formularioRegistro']);
$router->post('/registro', [WebAuthController::class, 'registro'], [$limiteRegistro]);
$router->post('/logout', [WebAuthController::class, 'logout']);

// --- API JSON ------------------------------------------------------------------
$router->get('/api', [InicioController::class, 'index']);

$router->get('/api/sesion', [ApiAuthController::class, 'sesion']);
$router->post('/api/registro', [ApiAuthController::class, 'registro'], [$limiteRegistro]);
$router->post('/api/login', [ApiAuthController::class, 'login'], [$limiteLogin]);
$router->post('/api/logout', [ApiAuthController::class, 'logout'], [Autenticado::class]);

$router->get('/api/usuarios', [UsuarioController::class, 'index'], [
    Autenticado::class,
    new RequierePermiso('usuarios.gestionar'),
]);
