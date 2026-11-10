<?php

declare(strict_types=1);

/*
 * Rutas del perfil del cliente, del formulario de contacto y de la parte de
 * administración del área interna: consultas, panel resumen, informes,
 * auditoría y exportación. Lo carga config/rutas.php.
 *
 * Los permisos de cada rol están en App\Seguridad\Permisos. Todo lo de
 * /api/gestion de este archivo es solo de administración (el almacén no tiene
 * estos permisos y recibe 403).
 */

use App\Controladores\Api\ConsultaController;
use App\Controladores\Api\Gestion\AuditoriaController;
use App\Controladores\Api\Gestion\ConsultaController as GestionConsultaController;
use App\Controladores\Api\Gestion\ExportacionController;
use App\Controladores\Api\Gestion\InformeController;
use App\Controladores\Api\PerfilController;
use App\Middlewares\Autenticado;
use App\Middlewares\LimitarTasa;
use App\Middlewares\RequierePermiso;
use App\Nucleo\Entorno;

/** @var \App\Nucleo\Router $router */

// --- Perfil del cliente ---------------------------------------------------------
// Siempre el del usuario de la sesión: la ruta no lleva id
$perfilPropio = [Autenticado::class, new RequierePermiso('perfil.propio')];
$router->get('/api/perfil', [PerfilController::class, 'ver'], $perfilPropio);
$router->patch('/api/perfil', [PerfilController::class, 'editar'], $perfilPropio);

// --- Formulario de contacto (público) -------------------------------------------
// Sin sesión, así que el límite es por IP: 5 consultas por hora. Se puede
// cambiar por entorno, como el del login y el del registro (config/rutas.php)
$limiteConsultas = new LimitarTasa('consulta', (int) Entorno::obtener('LIMITE_CONSULTA_IP', '5'), 3600);
$router->post('/api/consultas', [ConsultaController::class, 'crear'], [$limiteConsultas]);

// --- Área interna: administración ------------------------------------------------
$router->get('/api/gestion/consultas', [GestionConsultaController::class, 'index'], [
    Autenticado::class, new RequierePermiso('consultas.gestionar'),
]);
$router->patch('/api/gestion/consultas/{id}', [GestionConsultaController::class, 'cambiarEstado'], [
    Autenticado::class, new RequierePermiso('consultas.gestionar'),
]);

$verInformes = [Autenticado::class, new RequierePermiso('informes.ver')];
$router->get('/api/gestion/resumen', [InformeController::class, 'resumen'], $verInformes);
$router->get('/api/gestion/informes/{nombre}', [InformeController::class, 'ver'], $verInformes);

$router->get('/api/gestion/auditoria', [AuditoriaController::class, 'index'], [
    Autenticado::class, new RequierePermiso('auditoria.ver'),
]);

$router->get('/api/gestion/exportar/pedidos', [ExportacionController::class, 'pedidos'], [
    Autenticado::class, new RequierePermiso('datos.exportar'),
]);
