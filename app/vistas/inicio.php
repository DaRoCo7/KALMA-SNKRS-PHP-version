<?php
/**
 * Portada. De arriba abajo:
 *   1. Hero animado (la silueta iluminada).
 *   2. Lanzamientos: carrusel de zapatillas anunciadas que todavía no se venden.
 *      Entran de izquierda a derecha y debajo cambia la palabra de la casa.
 *   3. Disponibles: tres pares a la venta bajando bajo un rótulo fijo, y el
 *      enlace al catálogo.
 *   4. Personalización: la zapatilla, la prueba del bordado y los extras, todo
 *      en una pantalla y sin precios (se ven al comprar).
 *
 * @var string $titulo
 * @var string[] $palabras
 * @var list<array> $lanzamientos  zapatillas con fecha de lanzamiento futura
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
        <section class="lanzamientos" id="lanzamientos" data-carrusel aria-roledescription="carrusel" aria-labelledby="lanzamientos-titulo">
            <div class="lanzamientos__cabecera">
                <h2 class="lanzamientos__titulo" id="lanzamientos-titulo">Lanzamientos</h2>
                <p class="lanzamientos__nota">Todavía no se pueden comprar. Cada par llega al almacén el día que marca.</p>
            </div>

            <ul class="carrusel" data-carrusel-pista>
                <?php foreach ($lanzamientos as $i => $par): ?>
                    <li class="diapositiva<?= $i === 0 ? ' es-activa' : '' ?>" data-diapositiva
                        aria-roledescription="diapositiva" aria-label="<?= $i + 1 ?> de <?= count($lanzamientos) ?>">
                        <img class="diapositiva__foto" src="<?= e($par['imagen']) ?>" width="1200" height="855" draggable="false"
                             <?= $i === 0 ? '' : 'loading="lazy"' ?> alt="<?= e($par['nombre'] . ' ' . $par['colorway']) ?>">
                        <div class="diapositiva__datos">
                            <h3 class="diapositiva__nombre"><?= e($par['nombre']) ?></h3>
                            <p class="diapositiva__color"><?= e($par['colorway']) ?></p>
                            <p class="diapositiva__fecha">Sale el <?= e($fecha($par['fecha_lanzamiento'])) ?></p>
                            <p class="diapositiva__texto"><?= e($par['descripcion']) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="lanzamientos__pie">
                <div class="lanzamientos__palabras" aria-hidden="true">
                    <?php foreach ($palabras as $i => $palabra): ?>
                        <p class="lanzamientos__palabra<?= $i === 0 ? ' es-activa' : '' ?>" data-palabra><?= e($palabra) ?></p>
                    <?php endforeach; ?>
                </div>
                <div class="lanzamientos__mandos" data-carrusel-mandos hidden>
                    <p class="lanzamientos__cuenta" data-carrusel-cuenta>1 / <?= count($lanzamientos) ?></p>
                    <button class="boton boton--icono" type="button" data-carrusel-anterior aria-label="Lanzamiento anterior">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12H5M11 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.5"/></svg>
                    </button>
                    <button class="boton boton--icono" type="button" data-carrusel-siguiente aria-label="Lanzamiento siguiente">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.5"/></svg>
                    </button>
                </div>
            </div>
        </section>
<?php endif; ?>

<?php if ($disponibles !== []): ?>
        <section class="disponibles" id="disponibles">
            <div class="disponibles__fijo">
                <h2 class="disponibles__titulo">Disponibles</h2>
                <p class="disponibles__nota">Estos ya están en el almacén. Pocas unidades por talla.</p>
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

        <script type="module" src="/js/portada.js"></script>
<?php require __DIR__ . '/parciales/pie.php'; ?>
