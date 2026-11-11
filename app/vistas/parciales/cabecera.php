<?php
/**
 * Cabecera común de las páginas: <head> y barra de navegación.
 * Tres columnas: el botón «Menú» a la izquierda, el logo en el centro y la
 * cuenta con la cesta a la derecha. Las páginas, el buscador y los atajos del
 * catálogo viven en el panel del menú (parciales/menu.php).
 * Si hay sesión, la derecha muestra el nombre del usuario, que enlaza a su área
 * (/cuenta para los clientes, /panel para las cuentas de la tienda), y el botón
 * de cerrar sesión (un formulario POST con el token CSRF: cerrar sesión también
 * modifica datos).
 *
 * @var string $titulo
 * @var string[]|null $estilos    hojas de estilo propias de la página (rutas desde public/)
 * @var string|null   $claseMain  clase de <main>; la portada la deja vacía para ocupar todo el ancho
 */
$usuarioSesion = \App\Seguridad\Sesion::usuario();
// Adónde lleva «mi cuenta»: el cliente tiene su área y la tienda, el panel interno.
// Lo usa también menu.php; cesta.js repite la misma regla al entrar sin recargar
$areaSesion = ($usuarioSesion['rol'] ?? null) === 'cliente' ? '/cuenta' : '/panel';
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
    <link rel="stylesheet" href="/css/menu.css">
    <?php // La cesta está en todas las páginas: el contador de la cabecera y su panel ?>
    <script type="module" src="/js/cesta.js"></script>
    <script type="module" src="/js/menu.js"></script>
    <?php // Marca que hay JavaScript antes de pintar: las entradas animadas solo ocultan contenido si alguien lo va a mostrar ?>
    <script>document.documentElement.classList.add('con-js');</script>
<?php foreach ($estilos as $hoja): ?>
    <link rel="stylesheet" href="<?= e($hoja) ?>">
<?php endforeach; ?>
</head>
<body>
    <header class="cabecera">
        <div class="cabecera__interior">
            <?php
            // Un enlace al ancla del panel y no un botón: sin JavaScript sigue
            // abriendo el menú (con :target, ver menu.css). menu.js lo cambia por
            // un <button> con aria-expanded cuando puede abrirlo como diálogo.
            ?>
            <a class="boton-menu" href="#menu" aria-controls="menu" data-menu-abrir>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                <span class="boton-menu__texto">Menú</span>
            </a>
            <a class="marca" href="/">
                <img src="/img/logo-kalma.png" alt="KALMA SNKRS" width="640" height="275">
            </a>
            <div class="cuenta">
                <?php // Sin JavaScript no hay cesta (vive en el navegador): el botón solo se ve con JS ?>
                <button class="boton-cesta" type="button" data-cesta-abrir aria-haspopup="dialog" aria-controls="cesta" aria-label="Cesta vacía">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                    <span class="boton-cesta__numero" data-cesta-numero aria-hidden="true">0</span>
                </button>
                <?php
                // cesta.js sustituye estos hijos directos (enlaces, nombre y formulario)
                // al entrar desde la cesta: si cambia la estructura, hay que cambiarlo allí
                ?>
                <?php if ($usuarioSesion === null): ?>
                    <a href="/login">Entrar</a>
                    <a class="boton boton--claro cuenta__registro" href="/registro">Crear cuenta</a>
                <?php else: ?>
                    <a class="cuenta__nombre" href="<?= e($areaSesion) ?>"><?= e($usuarioSesion['nombre']) ?></a>
                    <form method="post" action="/logout" class="en-linea">
                        <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                        <button type="submit">Salir</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </header>
<?php require __DIR__ . '/menu.php'; ?>
<?php require __DIR__ . '/cesta.php'; ?>
    <main<?= $claseMain !== '' ? ' class="' . e($claseMain) . '"' : '' ?>>
