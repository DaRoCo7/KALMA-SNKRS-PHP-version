<?php

declare(strict_types=1);

/*
 * Rutas del catálogo interno (área de administración): alta, edición y
 * desactivación de zapatillas y carga y consulta de albaranes del proveedor en CSV.
 * Las carga config/rutas.php. Solo administración, salvo consultar los albaranes.
 */

use App\Controladores\Api\Gestion\AlbaranController;
use App\Controladores\Api\Gestion\ProductoController as GestionProductoController;
use App\Middlewares\Autenticado;
use App\Middlewares\RequierePermiso;

/** @var \App\Nucleo\Router $router */

$gestionProductos = [Autenticado::class, new RequierePermiso('productos.gestionar')];

$router->get('/api/gestion/productos', [GestionProductoController::class, 'index'], $gestionProductos);
$router->post('/api/gestion/productos', [GestionProductoController::class, 'crear'], $gestionProductos);
$router->get('/api/gestion/productos/{id}', [GestionProductoController::class, 'ver'], $gestionProductos);
$router->patch('/api/gestion/productos/{id}', [GestionProductoController::class, 'editar'], $gestionProductos);
// DELETE no borra: desactiva la zapatilla (los pedidos antiguos la siguen necesitando)
$router->delete('/api/gestion/productos/{id}', [GestionProductoController::class, 'desactivar'], $gestionProductos);

$router->post('/api/gestion/albaranes', [AlbaranController::class, 'cargar'], $gestionProductos);
// Avisos de referencias repetidas: solo administración, que es quien decide si se cargan igualmente
$router->get('/api/gestion/albaranes/avisos', [AlbaranController::class, 'avisos'], $gestionProductos);

// Consultar los albaranes ya cargados también lo puede hacer el almacén (albaranes.ver).
// La referencia del detalle va en ?referencia= porque puede llevar «/» (ALB/2026/42)
$verAlbaranes = [Autenticado::class, new RequierePermiso('albaranes.ver')];
$router->get('/api/gestion/albaranes', [AlbaranController::class, 'index'], $verAlbaranes);
$router->get('/api/gestion/albaranes/detalle', [AlbaranController::class, 'ver'], $verAlbaranes);
