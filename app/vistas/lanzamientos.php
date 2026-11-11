<?php
/**
 * Lanzamientos: las zapatillas anunciadas que todavía no se venden. PHP sirve
 * solo el esqueleto y public/js/lanzamientos.js pide los pares a la API y los
 * pinta. Cada uno abre su ficha en el panel de producto en modo consulta: se ve
 * todo menos la compra, que no existe hasta la fecha de salida.
 *
 * @var string $titulo
 */
$estilos = ['/css/lanzamientos.css', '/css/panel-producto.css'];
$claseMain = 'lanzamientos-pagina';

require __DIR__ . '/parciales/cabecera.php';
?>
        <h1 class="lanzamientos-pagina__titulo">Lanzamientos</h1>

        <noscript>
            <p class="lanzamientos-pagina__aviso">Los lanzamientos necesitan JavaScript para cargarse. Actívalo en tu navegador y recarga la página, o mira lo que ya está a la venta en el <a href="/catalogo">catálogo</a>.</p>
        </noscript>

        <div class="lanzamientos-pagina__estado" data-estado role="status">
            <p>Cargando lanzamientos…</p>
        </div>
        <ul class="anuncios" data-anuncios></ul>

        <script type="module" src="/js/lanzamientos.js"></script>
<?php require __DIR__ . '/parciales/pie.php'; ?>
