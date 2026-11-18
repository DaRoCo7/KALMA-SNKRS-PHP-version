<?php
/**
 * Sobre nosotros. De arriba abajo:
 *   1. Presentacion, identidad, herencia y cliente.
 *   2. La calle, con su fotografia y credito.
 *   3. Par a par, con los cuatro extras y su enlace.
 *
 * Todo el texto está aquí: no hay datos de la base de datos. Las fotos de la
 * calle y de la Jordan en la mano son ajenas y llevan su crédito al pie
 * (docs/imagenes.md); nunca se presentan como de KALMA.
 *
 * @var string $titulo
 */
$estilos = ['/css/nosotros.css'];
$claseMain = '';   // las secciones ocupan todo el ancho

$extras = [
    'Bordado'     => 'Hasta doce letras en el talón de cada zapatilla, en los modelos que lo admiten. Tu nombre, o el de quien lo va a heredar.',
    'Cordones'    => 'Un par de repuesto dentro de la caja, en negro, blanco o rojo, para cambiarle el carácter al par cuando te apetezca.',
    'Packaging'   => 'La caja edición KALMA, negra mate con el logo en relieve, o la de coleccionista, con el frontal transparente para tenerlas a la vista.',
    'Certificado' => 'Una tarjeta con el código de estilo del modelo y la etiqueta de su talla. La prueba de que ese par es el que es.',
];

require __DIR__ . '/parciales/cabecera.php';
?>
        <div class="escenario">
            <div class="escenario__texto">
                <section class="capitulo capitulo--inicio" aria-labelledby="nosotros-titulo">
                    <h1 class="nosotros__titulo" id="nosotros-titulo" data-entrada>Más que una zapatilla</h1>
                    <p class="nosotros__entrada" data-entrada>KALMA SNKRS es una tienda de pares exclusivos de Nike y Jordan. Lo que vendemos no se acaba en la caja: empieza cuando te las pones y sigue en todo lo que cuentan de ti.</p>
                </section>

                <section class="capitulo" aria-labelledby="capitulo-identidad">
                    <h2 class="capitulo__titulo" id="capitulo-identidad" data-entrada>Lo que llevas dice quién eres</h2>
                    <p data-entrada>Unas zapatillas se eligen como se elige una forma de hablar. La silueta, el color, si van impecables o con las marcas de un verano entero, con qué las combinas. Todo eso dice algo de ti antes de que abras la boca.</p>
                    <p data-entrada>Por eso no queremos venderte lo mismo que lleva todo el mundo. Buscamos pares con algo que contar y te damos lo necesario para acabar de hacerlos tuyos: tu nombre bordado, otros cordones, una caja a la altura. Un par con tu nombre en el talón ya no es un par cualquiera. Es el tuyo, y se nota.</p>
                </section>

                <section class="capitulo capitulo--herencia" aria-labelledby="capitulo-herencia">
                    <h2 class="capitulo__titulo" id="capitulo-herencia" data-entrada>Lo que pasa de mano en mano</h2>
                    <p data-entrada>En esta cultura hay pares que no se tiran. Se guardan en su caja, se limpian antes de una ocasión, se le dejan al hermano pequeño el día que le cabe el pie. Unas Jordan pueden pasar de padre a hija con la entresuela amarillenta y el valor intacto.</p>
                    <figure class="capitulo__figura" data-entrada>
                        <img src="/img/nosotros/air-jordan-1-en-la-mano.webp" width="1400" height="1154" loading="lazy"
                             alt="Una mano sostiene una Air Jordan 1 negra y roja contra una pared blanca">
                        <figcaption>Una Air Jordan 1 «Bred» en manos de su dueño. Foto: Ting Him Mak, CC BY 2.0, vía Wikimedia Commons.</figcaption>
                    </figure>
                    <p data-entrada>Lo que se hereda no es solo el cuero. Es la historia: dónde se compraron, qué día se estrenaron, quién las llevaba. Cada par que sale de KALMA puede ir con su certificado para que esa historia tenga escrito el primer capítulo.</p>
                </section>

                <section class="capitulo" aria-labelledby="capitulo-cliente">
                    <h2 class="capitulo__titulo" id="capitulo-cliente" data-entrada>Tú, en el centro</h2>
                    <p data-entrada>Ser una tienda pequeña tiene una ventaja: podemos mirar a cada persona. Aquí no hay pedidos anónimos. Cada pedido es un par concreto, en una talla concreta, para alguien que lo estaba esperando.</p>
                    <p data-entrada>Te enseñamos el stock real de cada talla, sin trucos de última unidad. Te contamos qué está por salir y en qué fecha. Y si cambias de idea, puedes cancelar tu pedido mientras no hayamos empezado a prepararlo, y el par vuelve a la estantería para el siguiente.</p>
                </section>
            </div>

        </div>

        <section class="calle" aria-labelledby="calle-titulo">
            <figure class="calle__figura" data-entrada>
                <img src="/img/nosotros/zapatillas-colgadas-berna.webp" width="1600" height="1067" loading="lazy"
                     alt="Decenas de zapatillas colgadas de un cable bajo un paso elevado de hormigón">
                <figcaption>Zapatillas colgadas de un cable bajo un paso elevado de Berna (Suiza). Foto: PeterSherman42, CC0, vía Wikimedia Commons.</figcaption>
            </figure>
            <div class="calle__texto">
                <h2 class="calle__titulo" id="calle-titulo" data-entrada>Viene de la calle</h2>
                <p data-entrada>Las zapatillas llegaron a la moda desde las canchas, los parques y los barrios, no al revés. Ahí se aprendió a cuidarlas, a cambiarles los cordones, a reconocer un modelo de lejos por la suela.</p>
                <p data-entrada>Hay quien las cuelga de un cable cuando termina una etapa, y cada barrio tiene su explicación. Esa es la cultura que respetamos: no venimos a inventarla, venimos a sumarnos a ella.</p>
            </div>
        </section>

        <section class="par-a-par" aria-labelledby="par-a-par-titulo">
            <div class="par-a-par__intro">
                <h2 class="par-a-par__titulo" id="par-a-par-titulo" data-entrada>Hecho par a par</h2>
                <p data-entrada>Cada par personalizado se prepara de uno en uno. Los extras se eligen al comprar, línea a línea, y su recargo se suma por par.</p>
                <a class="boton boton--claro" href="/#personalizacion" data-entrada>Prueba tu nombre bordado</a>
            </div>
            <dl class="extras-casa">
                <?php foreach ($extras as $nombre => $texto): ?>
                    <div class="extras-casa__extra" data-entrada>
                        <dt><?= e($nombre) ?></dt>
                        <dd><?= e($texto) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>

        <script type="module" src="/js/nosotros.js"></script>
<?php require __DIR__ . '/parciales/pie.php'; ?>
