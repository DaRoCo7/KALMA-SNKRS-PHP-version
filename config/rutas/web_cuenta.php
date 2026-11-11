<?php

declare(strict_types=1);

/*
 * Página del área del cliente (/cuenta). Lo carga config/rutas.php.
 *
 * Solo exige sesión: el contenido (pedidos y perfil) lo pide el JavaScript a
 * la API, que ya comprueba los permisos de cada dato. Si entra una cuenta de
 * la tienda (admin o almacén), el controlador le enseña un aviso con el enlace
 * a su panel en vez de una página que fallaría al cargar.
 */

use App\Controladores\Web\CuentaController;
use App\Middlewares\Autenticado;

/** @var \App\Nucleo\Router $router */

$router->get('/cuenta', [CuentaController::class, 'index'], [Autenticado::class]);
