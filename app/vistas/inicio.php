<?php
/**
 * Portada. De arriba abajo:
 *   1. Hero animado (la silueta iluminada).
 *   2. Lanzamientos: los tres más cercanos pasan en una cinta continua de
 *      izquierda a derecha, sin que nadie toque nada.
 *   3. Disponibles: tres pares a la venta bajando bajo un rótulo fijo, y el
 *      enlace al catálogo.
 *   4. Personalización: la zapatilla, la prueba del bordado y los extras, todo
 *      en una pantalla y sin precios (se ven al comprar).
 *   5. Las palabras de la casa, enormes, justo antes del pie.
 *
 * @var string $titulo
 * @var string[] $palabras
 * @var list<array> $lanzamientos  los lanzamientos más cercanos
 * @var list<array> $disponibles   últimos pares a la venta
 * @var list<array> $opciones      opciones de personalización activas
 */
$estilos = ['/css/hero-light.css', '/css/portada.css'];
$claseMain = '';   // la portada ocupa todo el ancho, sin el contenedor centrado

$precio = static fn (float $importe): string => number_format($importe, 2, ',', '.') . ' €';

// «2026-11-27» -> «27 de noviembre». A mano para no depender de la extensión intl.
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fecha = static function (string $iso) use ($meses): string {
    [, $mes, $dia] = array_map('intval', explode('-', $iso));
    return $dia . ' de ' . $meses[$mes - 1];
};

// Los extras se agrupan por tipo: cuatro bloques en vez de siete líneas sueltas
$tiposExtra = ['packaging' => 'Caja', 'bordado' => 'Bordado', 'cordones' => 'Cordones', 'certificado' => 'Certificado'];
$extras = [];
$bordado = null;
foreach ($opciones as $opcion) {
    $extras[$opcion['tipo']][] = $opcion;
    // El bordado es la única opción con texto: de ella sale el límite de letras de la prueba
    if ($bordado === null && $opcion['max_caracteres'] !== null) {
        $bordado = $opcion;
    }
}

require __DIR__ . '/parciales/cabecera.php';
require __DIR__ . '/parciales/hero-light.php';
?>

<?php if ($lanzamientos !== []): ?>
        <section class="lanzamientos" id="lanzamientos" aria-labelledby="lanzamientos-titulo">
            <div class="lanzamientos__cabecera">
                <h2 class="lanzamientos__titulo" id="lanzamientos-titulo">Lanzamientos</h2>
                <a class="boton" href="/lanzamientos">Ver todos</a>
            </div>

            <?php // Cinta continua: la lista se pinta dos veces seguidas y la pista se desplaza la
                  // mitad de su ancho, así al acabar la vuelta encaja sin salto. La copia no existe
                  // para el lector de pantalla ni para el teclado. ?>
            <div class="cinta">
                <div class="cinta__pista">
                    <?php foreach ([false, true] as $copia): ?>
                        <ul class="cinta__grupo"<?= $copia ? ' aria-hidden="true" inert' : '' ?>>
                            <?php foreach ($lanzamientos as $par): ?>
                                <li class="lanzamiento">
                                    <img class="lanzamiento__foto" src="<?= e($par['imagen']) ?>" width="1200" height="855" draggable="false"
                                         loading="lazy" alt="<?= $copia ? '' : e($par['nombre'] . ' ' . $par['colorway']) ?>">
                                    <h3 class="lanzamiento__nombre"><?= e($par['nombre']) ?></h3>
                                    <p class="lanzamiento__color"><?= e($par['colorway']) ?></p>
                                    <p class="lanzamiento__fecha">Sale el <?= e($fecha($par['fecha_lanzamiento'])) ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
<?php endif; ?>

<?php if ($disponibles !== []): ?>
        <section class="disponibles" id="disponibles">
            <div class="disponibles__fijo">
                <h2 class="disponibles__titulo">Disponibles</h2>
            </div>

            <ul class="disponibles__pares">
                <?php foreach ($disponibles as $par): ?>
                    <li class="par" data-par>
                        <img class="par__foto" src="<?= e($par['imagen']) ?>" width="1200" height="900" loading="lazy"
                             alt="<?= e($par['nombre'] . ' ' . $par['colorway']) ?>">
                        <div class="par__datos">
                            <h3 class="par__nombre"><?= e($par['nombre']) ?></h3>
                            <p class="par__color"><?= e($par['colorway']) ?></p>
                            <p class="par__precio"><?= e($precio($par['precio'])) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="disponibles__cierre" data-entrada>
                <a class="boton boton--grande" href="/catalogo">Ver todo el catálogo</a>
            </div>
        </section>
<?php endif; ?>

<?php if ($extras !== []): ?>
        <section class="personalizacion" id="personalizacion">
            <div class="personalizacion__escena">
                <img class="personalizacion__foto" src="/img/productos/cw2288-111.webp" width="1200" height="1200" loading="lazy"
                     alt="Nike Air Force 1 '07 blanca, uno de los modelos que admiten bordado">
                <?php if ($bordado !== null): ?>
                    <div class="bordado" data-bordado>
                        <svg class="bordado__muestra" viewBox="0 0 600 110" role="img" aria-label="Así quedaría tu nombre bordado">
                            <text x="300" y="84" text-anchor="middle" data-bordado-texto>TU NOMBRE</text>
                        </svg>
                        <label class="bordado__campo">
                            <span>Escribe tu nombre y míralo bordado</span>
                            <input type="text" maxlength="<?= (int) $bordado['max_caracteres'] ?>" placeholder="Tu nombre"
                                   autocomplete="off" spellcheck="false" data-bordado-entrada>
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="personalizacion__carta">
                <h2 class="personalizacion__titulo" data-entrada>Tu par, a tu manera</h2>
                <p class="personalizacion__texto" data-entrada>Cada par sale del almacén como tú lo pidas. Eliges los extras al comprar.</p>
                <ul class="extras">
                    <?php foreach ($tiposExtra as $tipo => $rotulo): ?>
                        <?php if (!isset($extras[$tipo])) { continue; } ?>
                        <li class="extra" data-entrada>
                            <h3 class="extra__tipo"><?= e($rotulo) ?></h3>
                            <ul class="extra__lista">
                                <?php foreach ($extras[$tipo] as $opcion): ?>
                                    <li><?= e($opcion['nombre']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
<?php endif; ?>

        <?php // Las palabras de la casa cierran la página, una por línea y a todo el ancho ?>
        <section class="palabras" aria-label="Así vivimos las zapatillas">
            <h2 class="palabras__lista">
                <?php foreach ($palabras as $palabra): ?>
                    <span class="palabras__palabra" data-entrada><?= e($palabra) ?></span>
                <?php endforeach; ?>
            </h2>
        </section>

        <script type="module" src="/js/portada.js"></script>
<?php require __DIR__ . '/parciales/pie.php'; ?>
