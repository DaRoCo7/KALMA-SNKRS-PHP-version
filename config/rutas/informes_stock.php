<?php

declare(strict_types=1);

/*
 * Informes de stock del almacén (poco stock y sin ventas desde hace N días).
 * Lo carga config/rutas.php.
 *
 * - informes_stock.enviar (almacén; administración lo tiene por '*'): vista
 *   previa, CSV, enviar y ver los enviados. El servicio limita al almacén a
 *   los suyos; con informes_stock.revisar se ven todos.
 * - informes_stock.revisar (solo administración): marcarlos como revisados.
 *
 * Las rutas fijas (vista-previa) van antes que las de {id}: el router se
 * queda con la primera que coincide.
 */

use App\Controladores\Api\Gestion\InformeStockController;
use App\Middlewares\Autenticado;
use App\Middlewares\RequierePermiso;

/** @var \App\Nucleo\Router $router */

$enviarInformes = [Autenticado::class, new RequierePermiso('informes_stock.enviar')];

$router->get('/api/gestion/informes-stock/vista-previa', [InformeStockController::class, 'vistaPrevia'], $enviarInformes);
$router->get('/api/gestion/informes-stock/vista-previa/csv', [InformeStockController::class, 'vistaPreviaCsv'], $enviarInformes);
$router->post('/api/gestion/informes-stock', [InformeStockController::class, 'enviar'], $enviarInformes);
$router->get('/api/gestion/informes-stock', [InformeStockController::class, 'index'], $enviarInformes);
$router->get('/api/gestion/informes-stock/{id}', [InformeStockController::class, 'ver'], $enviarInformes);
$router->get('/api/gestion/informes-stock/{id}/csv', [InformeStockController::class, 'csv'], $enviarInformes);
$router->patch('/api/gestion/informes-stock/{id}/revisado', [InformeStockController::class, 'marcarRevisado'], [
    Autenticado::class, new RequierePermiso('informes_stock.revisar'),
]);
