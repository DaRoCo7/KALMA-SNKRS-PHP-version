<?php
/**
 * Cabecera común de las páginas: <head> y barra de navegación.
 * Si hay sesión, muestra el nombre del usuario y el botón de cerrar sesión
 * (un formulario POST con el token CSRF: cerrar sesión también modifica datos).
 *
 * @var string $titulo
 */
$usuarioSesion = \App\Seguridad\Sesion::usuario();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> | KALMA SNKRS</title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
    <header class="cabecera">
        <div class="contenedor">
            <a class="marca" href="/">KALMA SNKRS</a>
            <nav>
                <a href="/catalogo">Catálogo</a>
                <?php if ($usuarioSesion === null): ?>
                    <a href="/login">Iniciar sesión</a>
                    <a href="/registro">Crear cuenta</a>
                <?php else: ?>
                    <span>Hola, <?= e($usuarioSesion['nombre']) ?> (<?= e($usuarioSesion['rol']) ?>)</span>
                    <form method="post" action="/logout" class="en-linea">
                        <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                        <button type="submit">Cerrar sesión</button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <main class="contenedor">
