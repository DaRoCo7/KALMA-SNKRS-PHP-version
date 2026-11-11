<?php
/**
 * Cabecera común de las páginas: <head> y barra de navegación.
 * Logo a la izquierda, páginas en el centro y la cuenta a la derecha.
 * Si hay sesión, muestra el nombre del usuario y el botón de cerrar sesión
 * (un formulario POST con el token CSRF: cerrar sesión también modifica datos).
 *
 * @var string $titulo
 * @var string[]|null $estilos    hojas de estilo propias de la página (rutas desde public/)
 * @var string|null   $claseMain  clase de <main>; la portada la deja vacía para ocupar todo el ancho
 */
$usuarioSesion = \App\Seguridad\Sesion::usuario();
$estilos ??= [];
$claseMain ??= 'contenedor';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> | KALMA SNKRS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/estilos.css">
    <?php // Marca que hay JavaScript antes de pintar: las entradas animadas solo ocultan contenido si alguien lo va a mostrar ?>
    <script>document.documentElement.classList.add('con-js');</script>
<?php foreach ($estilos as $hoja): ?>
    <link rel="stylesheet" href="<?= e($hoja) ?>">
<?php endforeach; ?>
</head>
<body>
    <header class="cabecera">
        <div class="cabecera__interior">
            <a class="marca" href="/">
                <img src="/img/logo-kalma.png" alt="KALMA SNKRS" width="640" height="275">
            </a>
            <nav class="navegacion" aria-label="Principal">
                <a href="/catalogo">Catálogo</a>
                <a href="/#lanzamientos">Lanzamientos</a>
                <a href="/#personalizacion">Personalización</a>
            </nav>
            <div class="cuenta">
                <?php if ($usuarioSesion === null): ?>
                    <a href="/login">Entrar</a>
                    <a class="boton boton--claro" href="/registro">Crear cuenta</a>
                <?php else: ?>
                    <span class="cuenta__nombre"><?= e($usuarioSesion['nombre']) ?></span>
                    <form method="post" action="/logout" class="en-linea">
                        <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                        <button type="submit">Salir</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main<?= $claseMain !== '' ? ' class="' . e($claseMain) . '"' : '' ?>>
