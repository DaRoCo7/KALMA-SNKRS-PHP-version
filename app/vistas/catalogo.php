<?php
/**
 * Catálogo. PHP sirve solo el esqueleto (título, filtros y huecos) y
 * public/js/catalogo.js pide las zapatillas a la API y las pinta. La ficha de
 * cada una se abre encima, en el panel de producto, y la cesta también vive en
 * un panel: toda la compra ocurre sin salir de esta página.
 *
 * @var string $titulo
 * @var array<string, string> $ordenes  valor del parámetro «orden» => texto
 */
$estilos = ['/css/catalogo.css', '/css/panel-producto.css'];
$claseMain = 'catalogo';

require __DIR__ . '/parciales/cabecera.php';
?>
        <div class="catalogo__cabecera">
            <h1 class="catalogo__titulo">Catálogo</h1>
            <p class="catalogo__recuento" data-recuento aria-live="polite"></p>
        </div>

        <noscript>
            <p class="catalogo__aviso">El catálogo necesita JavaScript para cargar las zapatillas y la cesta. Actívalo en tu navegador y recarga la página.</p>
        </noscript>

        <form class="filtros" data-filtros role="search" aria-label="Filtrar el catálogo">
            <div class="filtros__buscar">
                <label class="filtros__etiqueta" for="filtro-buscar">Buscar</label>
                <input id="filtro-buscar" name="buscar" type="search" maxlength="60" placeholder="Air Force, Jordan 1, Dunk…" autocomplete="off">
            </div>
            <fieldset class="filtros__categorias" hidden>
                <legend class="filtros__etiqueta">Categoría</legend>
                <div class="chips" data-categorias></div>
            </fieldset>
            <div class="filtros__orden">
                <label class="filtros__etiqueta" for="filtro-orden">Ordenar</label>
                <select id="filtro-orden" name="orden">
<?php foreach ($ordenes as $valor => $texto): ?>
                    <option value="<?= e($valor) ?>"<?= $valor === 'novedades' ? ' selected' : '' ?>><?= e($texto) ?></option>
<?php endforeach; ?>
                </select>
            </div>
        </form>

        <div class="catalogo__estado" data-estado-listado role="status">
            <p>Cargando zapatillas…</p>
        </div>
        <ul class="rejilla" data-rejilla></ul>

        <div class="catalogo__mas">
            <button class="boton boton--claro catalogo__boton-mas" type="button" data-cargar-mas hidden>Cargar más</button>
        </div>

        <script type="module" src="/js/catalogo.js"></script>
<?php require __DIR__ . '/parciales/pie.php'; ?>
