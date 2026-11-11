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
    <?php // El JavaScript lo lee para las peticiones que modifican datos (contrato, punto 9.2) ?>
    <meta name="csrf-token" content="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/estilos.css">
    <link rel="stylesheet" href="/css/cesta.css">
    <?php // La cesta está en todas las páginas: el contador de la cabecera y su panel ?>
    <script type="module" src="/js/cesta.js"></script>
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
                <a href="/lanzamientos">Lanzamientos</a>
                <a href="/#personalizacion">Personalización</a>
            </nav>
            <div class="cuenta">
                <?php // Sin JavaScript no hay cesta (vive en el navegador): el botón solo se ve con JS ?>
                <button class="boton-cesta" type="button" data-cesta-abrir aria-haspopup="dialog" aria-controls="cesta" aria-label="Cesta vacía">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                    <span class="boton-cesta__numero" data-cesta-numero aria-hidden="true">0</span>
                </button>
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
<?php require __DIR__ . '/cesta.php'; ?>
    <main<?= $claseMain !== '' ? ' class="' . e($claseMain) . '"' : '' ?>>
