<?php
/**
 * Panel lateral del menú, común a todas las páginas (lo incluye la cabecera).
 * Todo va en el HTML, también las categorías, para que el menú funcione igual
 * sin JavaScript: los atajos son enlaces normales al catálogo con el filtro en
 * la URL (?buscar=, ?categoria=), que es lo que catalogo.js lee al cargar.
 *
 * Es un <dialog>: con JavaScript se abre con showModal() (foco dentro, fondo
 * inerte, Escape). Sin JavaScript se muestra con :target al seguir el enlace
 * #menu de la cabecera, y «Cerrar» lleva a # (ver menu.css).
 *
 * @var array|null $usuarioSesion  lo deja cabecera.php
 * @var string     $areaSesion     también de cabecera.php: /cuenta o /panel según el rol
 */

// Las categorías salen de la base de datos en cada página. Si la consulta
// falla, el menú se queda sin ese grupo: no merece tumbar la página entera.
try {
    $categoriasMenu = (new \App\Servicios\CatalogoServicio())->categoriasMenu();
} catch (\Throwable) {
    $categoriasMenu = [];
}

// Búsquedas por modelo: texto que se busca en el nombre (el catálogo busca con ILIKE)
$modelosMenu = ['Air Jordan 1' => 'Jordan 1', 'Air Jordan 4' => 'Jordan 4', 'Air Max' => 'Air Max',
    'Dunk' => 'Dunk', 'Air Force 1' => 'Air Force 1', 'Blazer' => 'Blazer'];

$paginasMenu = ['/' => 'Inicio', '/catalogo' => 'Catálogo', '/lanzamientos' => 'Lanzamientos',
    '/#personalizacion' => 'Personalización', '/sobre-nosotros' => 'Sobre nosotros'];

// Marca la página actual para el lector de pantalla y para el estilo
$rutaActual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
?>
    <dialog class="menu" id="menu" aria-labelledby="menu-titulo">
        <div class="menu__caja">
            <div class="menu__cabecera">
                <h2 class="menu__titulo" id="menu-titulo">Menú</h2>
                <a class="boton boton--icono boton--claro" href="#" data-menu-cerrar aria-label="Cerrar el menú">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </a>
            </div>

            <?php // GET normal al catálogo: sin JavaScript la búsqueda llega igual ?>
            <form class="menu__buscar" action="/catalogo" method="get" role="search">
                <label class="menu__etiqueta" for="menu-buscar">Buscar zapatillas</label>
                <div class="menu__buscar-fila">
                    <input id="menu-buscar" name="buscar" type="search" maxlength="60" placeholder="Air Force, Jordan 1, Dunk…" autocomplete="off">
                    <button class="boton" type="submit">Buscar</button>
                </div>
            </form>

            <nav class="menu__paginas" aria-label="Páginas">
                <ul>
<?php foreach ($paginasMenu as $ruta => $texto): ?>
                    <li><a href="<?= e($ruta) ?>"<?= $ruta === $rutaActual ? ' aria-current="page"' : '' ?>><?= e($texto) ?></a></li>
<?php endforeach; ?>
                </ul>
            </nav>

            <div class="menu__atajos">
<?php if ($categoriasMenu !== []): ?>
                <nav aria-labelledby="menu-categorias">
                    <h3 class="menu__etiqueta" id="menu-categorias">Por categoría</h3>
                    <ul class="menu__chips">
<?php foreach ($categoriasMenu as $categoria): ?>
                        <li><a href="/catalogo?categoria=<?= (int) $categoria['id'] ?>"><?= e($categoria['nombre']) ?></a></li>
<?php endforeach; ?>
                    </ul>
                </nav>
<?php endif; ?>
                <nav aria-labelledby="menu-modelos">
                    <h3 class="menu__etiqueta" id="menu-modelos">Por modelo</h3>
                    <ul class="menu__chips">
<?php foreach ($modelosMenu as $texto => $busqueda): ?>
                        <li><a href="/catalogo?buscar=<?= e(rawurlencode($busqueda)) ?>"><?= e($texto) ?></a></li>
<?php endforeach; ?>
                    </ul>
                </nav>
            </div>

            <?php // En el móvil la cabecera solo tiene sitio para «Entrar»: aquí está todo lo de la cuenta ?>
            <div class="menu__cuenta">
<?php if ($usuarioSesion === null): ?>
                <a class="boton" href="/login">Entrar</a>
                <a class="boton boton--claro menu__registro" href="/registro">Crear cuenta</a>
<?php else: ?>
                <p class="menu__nombre">Hola, <?= e($usuarioSesion['nombre']) ?></p>
                <a class="boton" href="<?= e($areaSesion) ?>"<?= $areaSesion === $rutaActual ? ' aria-current="page"' : '' ?>><?= $areaSesion === '/cuenta' ? 'Mi cuenta' : 'Panel interno' ?></a>
                <form method="post" action="/logout" class="en-linea">
                    <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                    <button class="boton boton--claro menu__registro" type="submit">Cerrar sesión</button>
                </form>
<?php endif; ?>
            </div>
        </div>
    </dialog>
