<?php

declare(strict_types=1);

/*
 * Tabla de rutas de la aplicación.
 *
 *   - Páginas HTML (las genera el servidor): /catalogo, /login, /registro...
 *   - API JSON: las rutas que empiezan por /api
 *   - Sistema: /health (contrato con Despliegue)
 *
 * Formato: $router->metodo('/ruta/{parametro}', [Controlador::class, 'metodo'], [middlewares]);
 * Los permisos de cada rol están en App\Seguridad\Permisos (matriz de permisos).
 */

use App\Controladores\Api\AuthController as ApiAuthController;
use App\Controladores\Api\InicioController;
use App\Controladores\Api\PedidoController;
use App\Controladores\Api\UsuarioController;
use App\Controladores\SaludController;
use App\Controladores\Web\AuthController as WebAuthController;
use App\Controladores\Web\CatalogoController;
use App\Controladores\Web\InicioController as WebInicioController;
use App\Controladores\Web\LanzamientosController;
use App\Controladores\Web\NosotrosController;
use App\Middlewares\Autenticado;
use App\Middlewares\IniciarSesion;
use App\Middlewares\LimitarTasa;
use App\Middlewares\RequierePermiso;
use App\Middlewares\VerificarCsrf;
use App\Nucleo\Entorno;
use App\Controladores\Api\CatalogoController as ApiCatalogoController;
use App\Controladores\Api\LanzamientosController as ApiLanzamientosController;

/** @var \App\Nucleo\Router $router */

// --- Middlewares comunes a todas las rutas (en este orden) ----------------------
$router->usar(new IniciarSesion());
$router->usar(new VerificarCsrf());

// Rate limiting por IP en la aplicación (además del de Nginx). Los valores se
// pueden cambiar por entorno: en desarrollo (compose.dev.yaml) son más altos
// para poder repetir las pruebas de Postman; el límite POR CUENTA (5 intentos
// de login cada 15 min, en AuthServicio) no cambia nunca.
$limiteLogin    = new LimitarTasa('login', (int) Entorno::obtener('LIMITE_LOGIN_IP', '20'), 900);        // por IP cada 15 min
$limiteRegistro = new LimitarTasa('registro', (int) Entorno::obtener('LIMITE_REGISTRO_IP', '5'), 3600);  // por IP y hora

// --- Sistema ------------------------------------------------------------------
$router->get('/health', [SaludController::class, 'comprobar']);

// --- Páginas HTML --------------------------------------------------------------
$router->get('/', [WebInicioController::class, 'index']);
$router->get('/catalogo', [CatalogoController::class, 'index']);
$router->get('/lanzamientos', [LanzamientosController::class, 'index']);
$router->get('/sobre-nosotros', [NosotrosController::class, 'index']);

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

// Catálogo público (sin sesión)
$router->get('/api/productos', [ApiCatalogoController::class, 'index']);
$router->get('/api/productos/{slug}', [ApiCatalogoController::class, 'ver']);
$router->get('/api/catalogo/filtros', [ApiCatalogoController::class, 'filtros']);
// Los anunciados que todavía no se venden: el catálogo de arriba no los lista
$router->get('/api/lanzamientos', [ApiLanzamientosController::class, 'index']);

// Pedidos propios del cliente. Que el pedido sea SUYO lo comprueba el servicio:
// el de otro cliente responde 404, como si no existiera (protección IDOR)
$pedidosPropios = [Autenticado::class, new RequierePermiso('pedidos.propios')];
$router->get('/api/pedidos', [PedidoController::class, 'index'], $pedidosPropios);
$router->post('/api/pedidos', [PedidoController::class, 'crear'], $pedidosPropios);
$router->get('/api/pedidos/{codigo}', [PedidoController::class, 'ver'], $pedidosPropios);
$router->post('/api/pedidos/{codigo}/cancelar', [PedidoController::class, 'cancelar'], $pedidosPropios);

// Administración
$router->get('/api/usuarios', [UsuarioController::class, 'index'], [
    Autenticado::class,
    new RequierePermiso('usuarios.gestionar'),
]);

// --- Módulos ------------------------------------------------------------------
// Cada módulo (perfil, área interna, informes...) declara sus rutas en su propio
// archivo de config/rutas/. Se cargan en orden alfabético: así un módulo nuevo
// no obliga a tocar este archivo y cada parte del equipo trabaja en el suyo.
foreach (glob(__DIR__ . '/rutas/*.php') ?: [] as $archivoRutas) {
    require $archivoRutas;
}
