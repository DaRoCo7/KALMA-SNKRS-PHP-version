<?php
/**
 * Hero animado de la portada: una luz que sigue al puntero y recorta la silueta
 * de una zapatilla. El dibujo lo hace public/js/hero-light.js en el canvas; la
 * imagen solo se enseña si el navegador no tiene WebGL2 o JavaScript.
 *
 * Se incluye justo después de la cabecera, fuera de <main class="contenedor">,
 * porque ocupa todo el ancho.
 *
 * Siluetas: PNG con fondo transparente en public/img/siluetas/<código>.png, todas
 * de perfil, con la puntera a la derecha y recortadas al borde de la zapatilla
 * (sin la sombra del suelo), para que el mismo encaje del shader valga para todas.
 */

// Cada carga de la página sortea una. Se elige aquí, en el servidor, y no en el
// JavaScript: así la imagen que lee el canvas, la de respaldo (sin WebGL2 o con
// JavaScript desactivado) y su texto alternativo son siempre la misma zapatilla,
// y el navegador descarga una sola silueta en lugar de todas.
// La portada sale con Cache-Control: no-store, así que cada recarga vuelve a sortear.
$siluetas = [
    ['codigo' => 'dz5485-612', 'alt' => 'Air Jordan 1 Retro High OG «Lost & Found» Chicago', 'ancho' => 1200, 'alto' => 718],
    ['codigo' => 'dn3707-010', 'alt' => 'Air Jordan 3 Retro «Black Cement Reimagined»',      'ancho' => 1200, 'alto' => 692],
    ['codigo' => 'fv5029-006', 'alt' => 'Air Jordan 4 Retro «Bred Reimagined»',              'ancho' => 1200, 'alto' => 757],
    ['codigo' => 'hf3975-001', 'alt' => 'Air Jordan 5 Retro OG «Black Metallic Reimagined»', 'ancho' => 1200, 'alto' => 819],
    ['codigo' => 'cw2288-001', 'alt' => "Nike Air Force 1 '07 negra",                        'ancho' => 1200, 'alto' => 606],
    ['codigo' => 'ct1689-001', 'alt' => 'Nike Air Max 95 OG «Neon»',                         'ancho' => 1200, 'alto' => 620],
    ['codigo' => 'dm0028-002', 'alt' => 'Nike Air Max 97 OG «Silver Bullet»',                'ancho' => 1200, 'alto' => 581],
];
$silueta = $siluetas[random_int(0, count($siluetas) - 1)];
$rutaSilueta = '/img/siluetas/' . $silueta['codigo'] . '.png';
?>
<section class="hero-light" data-hero-light>
    <h1 class="hero-light__titulo">Sneaker Factory</h1>
    <canvas class="hero-light__lienzo" data-hero-lienzo aria-hidden="true"></canvas>
    <img class="hero-light__imagen" data-hero-imagen src="<?= e($rutaSilueta) ?>"
         alt="<?= e($silueta['alt']) ?>" width="<?= $silueta['ancho'] ?>" height="<?= $silueta['alto'] ?>" hidden>
    <noscript>
        <img class="hero-light__imagen" src="<?= e($rutaSilueta) ?>"
             alt="<?= e($silueta['alt']) ?>" width="<?= $silueta['ancho'] ?>" height="<?= $silueta['alto'] ?>">
    </noscript>
</section>
<script type="module" src="/js/hero-light.js"></script>
