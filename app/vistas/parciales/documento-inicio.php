<?php
/**
 * Inicio de documento mínimo: el mismo <head> que cabecera.php, sin barra de
 * navegación, sin cesta y sin pie.
 * Es para las pantallas de un solo paso (el login): ahí la navegación solo
 * distrae y la cesta no pinta nada, así que la página se queda en lo que se
 * viene a hacer. Se cierra con documento-fin.php.
 *
 * @var string        $titulo
 * @var string[]|null $estilos    hojas de estilo propias de la página (rutas desde public/)
 * @var string|null   $claseBody  clase de <body>; el fondo va en el body para que el
 *                                rebote del scroll no enseñe otro color por los bordes
 */
$estilos ??= [];
$claseBody ??= '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> | KALMA SNKRS</title>
    <?php // Igual que en cabecera.php: el JavaScript lo lee para las peticiones que modifican datos ?>
    <meta name="csrf-token" content="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/estilos.css">
    <?php // Las entradas animadas solo ocultan contenido si hay JavaScript para mostrarlo ?>
    <script>document.documentElement.classList.add('con-js');</script>
<?php foreach ($estilos as $hoja): ?>
    <link rel="stylesheet" href="<?= e($hoja) ?>">
<?php endforeach; ?>
</head>
<body<?= $claseBody !== '' ? ' class="' . e($claseBody) . '"' : '' ?>>
    <main>
