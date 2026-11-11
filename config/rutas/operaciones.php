<?php

declare(strict_types=1);

/*
 * Rutas de la operación interna: gestión de pedidos (estados y logística) y
 * control de stock. Las usan almacén y administración.
 *
 * El middleware de cada ruta comprueba el permiso general. La regla más fina
 * de los estados (confirmar el pago y cancelar solo administración) depende del
 * cuerpo de la petición y la aplica PedidoServicio::cambiarEstado().
 */

use App\Controladores\Api\Gestion\PedidoController as GestionPedidoController;
use App\Controladores\Api\Gestion\StockController as GestionStockController;
use App\Middlewares\Autenticado;
use App\Middlewares\RequierePermiso;

/** @var \App\Nucleo\Router $router */

// --- Pedidos de todos los clientes ---------------------------------------------
$verTodos = [Autenticado::class, new RequierePermiso('pedidos.ver_todos')];
$router->get('/api/gestion/pedidos', [GestionPedidoController::class, 'index'], $verTodos);
$router->get('/api/gestion/pedidos/{codigo}', [GestionPedidoController::class, 'ver'], $verTodos);
$router->patch('/api/gestion/pedidos/{codigo}/estado', [GestionPedidoController::class, 'cambiarEstado'], [
    Autenticado::class,
    new RequierePermiso('pedidos.cambiar_estado'),
]);

// --- Stock de las tallas ---------------------------------------------------------
$gestionarStock = [Autenticado::class, new RequierePermiso('stock.gestionar')];
$router->get('/api/gestion/stock', [GestionStockController::class, 'index'], $gestionarStock);
$router->patch('/api/gestion/variantes/{id}/stock', [GestionStockController::class, 'ajustar'], $gestionarStock);
$router->get('/api/gestion/variantes/{id}/movimientos', [GestionStockController::class, 'movimientos'], $gestionarStock);
