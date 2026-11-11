<?php
/**
 * Área del cliente. PHP sirve el esqueleto (títulos, huecos y el formulario
 * vacío) y public/js/cuenta.js pide a la API los pedidos y el perfil, los pinta
 * y envía los cambios. Ningún dato de pedidos se escribe aquí.
 *
 * Las cuentas de la tienda no tienen perfil de cliente: en vez de la página,
 * ven un aviso con el enlace a su panel (lo decide CuentaController).
 *
 * @var string $titulo
 * @var string $nombre     nombre del usuario de la sesión, para el saludo
 * @var bool   $esCliente
 */
$estilos = ['/css/cuenta.css'];
$claseMain = 'contenedor mi-cuenta';

// Los campos editables del perfil (PerfilServicio::EDITABLES) con su etiqueta,
// su autocompletado y su tamaño máximo, que es el mismo que valida la API
$camposPerfil = [
    'nombre'        => ['Nombre', 'given-name', 100, 'text', true],
    'apellidos'     => ['Apellidos', 'family-name', 150, 'text', true],
    'telefono'      => ['Teléfono', 'tel', 20, 'tel', false],
    'direccion'     => ['Dirección', 'street-address', 200, 'text', false],
    'codigo_postal' => ['Código postal', 'postal-code', 5, 'text', false],
    'ciudad'        => ['Ciudad', 'address-level2', 100, 'text', false],
    'provincia'     => ['Provincia', 'address-level1', 100, 'text', false],
];

require __DIR__ . '/parciales/cabecera.php';
?>
        <div class="mi-cuenta__cabecera">
            <h1 class="mi-cuenta__titulo">Mi cuenta</h1>
            <p class="mi-cuenta__saludo">Hola, <span data-saludo-nombre><?= e($nombre) ?></span>.</p>
        </div>

<?php if (!$esCliente): ?>
        <div class="mi-cuenta__aviso">
            <p>Esta es una cuenta de la tienda: no tiene pedidos ni datos de cliente. Lo tuyo está en el panel interno.</p>
            <a class="boton" href="/panel">Ir al panel</a>
        </div>
<?php else: ?>
        <noscript>
            <p class="mi-cuenta__aviso">Tus pedidos y tus datos se cargan con JavaScript. Actívalo en tu navegador y recarga la página.</p>
        </noscript>

        <div class="mi-cuenta__rejilla">
            <section class="pedidos" aria-labelledby="pedidos-titulo">
                <h2 class="mi-cuenta__seccion" id="pedidos-titulo">Mis pedidos</h2>
                <div class="pedidos__estado" data-pedidos-estado role="status">
                    <p>Cargando tus pedidos…</p>
                </div>
                <ul class="pedidos__lista" data-pedidos></ul>
                <button class="boton boton--claro pedidos__mas" type="button" data-pedidos-mas hidden>Ver más pedidos</button>
            </section>

            <section class="datos" aria-labelledby="datos-titulo">
                <h2 class="mi-cuenta__seccion" id="datos-titulo">Mis datos</h2>
                <form class="datos__formulario" data-perfil novalidate>
                    <?php // El email es el usuario de acceso: se enseña, pero no se edita (ver PerfilServicio) ?>
                    <div class="campo campo--fijo">
                        <span class="campo__etiqueta">Email</span>
                        <span class="campo__valor" data-perfil-email>—</span>
                    </div>
<?php foreach ($camposPerfil as $campo => [$etiqueta, $autocompletar, $maximo, $tipo, $obligatorio]): ?>
                    <div class="campo campo--<?= e(str_replace('_', '-', $campo)) ?>">
                        <label for="perfil-<?= e($campo) ?>"><?= e($etiqueta) ?><?php if (!$obligatorio): ?> <span class="campo__opcional">(opcional)</span><?php endif; ?></label>
                        <input id="perfil-<?= e($campo) ?>" name="<?= e($campo) ?>" type="<?= e($tipo) ?>"
                               autocomplete="<?= e($autocompletar) ?>" maxlength="<?= (int) $maximo ?>"
                               <?= $campo === 'codigo_postal' ? 'inputmode="numeric" ' : '' ?>aria-describedby="perfil-<?= e($campo) ?>-error" disabled>
                        <p class="campo__error" id="perfil-<?= e($campo) ?>-error"></p>
                    </div>
<?php endforeach; ?>
                    <div class="datos__pie">
                        <button class="boton" type="submit" disabled>Guardar cambios</button>
                        <p class="datos__aviso" data-perfil-aviso role="status"></p>
                    </div>
                </form>
            </section>
        </div>

        <?php // Confirmación antes de cancelar: la cancelación no tiene vuelta atrás ?>
        <dialog class="confirmar" data-confirmar aria-labelledby="confirmar-titulo">
            <form class="confirmar__caja" method="dialog" data-confirmar-formulario>
                <h2 class="confirmar__titulo" id="confirmar-titulo">¿Cancelar el pedido <span data-confirmar-codigo></span>?</h2>
                <p class="confirmar__texto">Se anula el pedido y las tallas vuelven a estar a la venta. No se puede deshacer.</p>
                <div class="campo">
                    <label for="confirmar-motivo">Motivo <span class="campo__opcional">(opcional)</span></label>
                    <textarea id="confirmar-motivo" name="motivo" rows="2" maxlength="200"></textarea>
                </div>
                <p class="campo__error" data-confirmar-error role="alert"></p>
                <div class="confirmar__botones">
                    <button class="boton boton--claro confirmar__volver" type="button" data-confirmar-volver>Volver</button>
                    <button class="boton" type="submit" data-confirmar-si>Sí, cancelar el pedido</button>
                </div>
            </form>
        </dialog>

        <script type="module" src="/js/cuenta.js"></script>
<?php endif; ?>
<?php require __DIR__ . '/parciales/pie.php'; ?>
