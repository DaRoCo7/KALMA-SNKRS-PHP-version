<?php
/**
 * Hero animado de la portada: una luz que sigue al puntero y recorta la silueta
 * de la Air Jordan 1 Chicago. El dibujo lo hace public/js/hero-light.js en el
 * canvas; la imagen solo se enseña si el navegador no tiene WebGL2 o JavaScript.
 *
 * Se incluye justo después de la cabecera, fuera de <main class="contenedor">,
 * porque ocupa todo el ancho.
 */
?>
<section class="hero-light" data-hero-light>
    <h1 class="hero-light__titulo">Sneaker Factory</h1>
    <canvas class="hero-light__lienzo" data-hero-lienzo aria-hidden="true"></canvas>
    <img class="hero-light__imagen" data-hero-imagen src="/img/jordan1-chicago.png"
         alt="Air Jordan 1 Retro High OG Chicago" width="1200" height="718" hidden>
    <noscript>
        <img class="hero-light__imagen" src="/img/jordan1-chicago.png"
             alt="Air Jordan 1 Retro High OG Chicago" width="1200" height="718">
    </noscript>
    <?php // Texto sobre el efecto: con mezcla por diferencia se lee tanto en la zona oscura como bajo la luz ?>
    <p class="hero-light__frase">Zapatillas exclusivas, personalizadas par a par.</p>
    <a class="boton boton--claro hero-light__accion" href="#lanzamientos">Ver lanzamientos</a>
</section>
<script type="module" src="/js/hero-light.js"></script>
