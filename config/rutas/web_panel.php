<?php

declare(strict_types=1);

/*
 * Página del área interna. Una sola ruta: las secciones (resumen, pedidos y
 * las que vengan) se eligen con el hash de la URL (/panel#pedidos) y cada una
 * pide sus datos a la API, que es la que aplica los permisos de cada sección.
 *
 * panel.acceder deja entrar a administración y almacén. Sin sesión, Autenticado
 * lleva al login; un cliente recibe la página de error 403.
 */

use App\Controladores\Web\PanelController;
use App\Middlewares\Autenticado;
use App\Middlewares\RequierePermiso;

/** @var \App\Nucleo\Router $router */

$router->get('/panel', [PanelController::class, 'index'], [
    Autenticado::class,
    new RequierePermiso('panel.acceder'),
]);
