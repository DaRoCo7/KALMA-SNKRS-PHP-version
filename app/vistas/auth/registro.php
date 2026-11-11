<?php
/**
 * Registro de clientes, con la misma pantalla partida que el login.
 * Primero lo imprescindible para tener cuenta; los datos de envío van aparte y
 * son opcionales, porque se pueden completar después en el perfil.
 *
 * @var array<string, string> $valores  lo que escribió el usuario (sin contraseña)
 * @var array<string, string> $errores  campo => motivo
 * @var string|null           $error    mensaje general
 * @var string|null           $volver   ruta interna a la que volver tras registrarse
 */
$titulo = 'Crear cuenta';
$estilos = ['/css/acceso.css'];
$claseMain = '';
$volver ??= null;

$sufijoVolver = $volver !== null ? '?volver=' . rawurlencode($volver) : '';

/**
 * Pinta un campo con su etiqueta, su valor anterior, su pista y su error.
 * La pista y el error se enlazan con aria-describedby para que el lector de
 * pantalla los lea al llegar al campo, no solo quien mira la página.
 */
$campo = static function (string $nombre, string $etiqueta, array $opciones = []) use ($valores, $errores): void {
    $tipo = $opciones['tipo'] ?? 'text';
    $mensaje = $errores[$nombre] ?? null;
    $pista = $opciones['pista'] ?? null;
    $describe = array_filter([
        $pista !== null ? 'pista-' . $nombre : null,
        $mensaje !== null ? 'error-' . $nombre : null,
    ]);
    $atributos = [
        'id'           => $nombre,
        'name'         => $nombre,
        'type'         => $tipo,
        'maxlength'    => (string) ($opciones['maximo'] ?? 100),
        'autocomplete' => $opciones['autocompletar'] ?? 'off',
        'value'        => $tipo === 'password' ? '' : ($valores[$nombre] ?? ''),
    ];
    if (isset($opciones['modoTeclado'])) {
        $atributos['inputmode'] = $opciones['modoTeclado'];
    }
    if ($tipo === 'email') {
        $atributos['spellcheck'] = 'false';
        $atributos['autocapitalize'] = 'none';
    }
    if ($describe !== []) {
        $atributos['aria-describedby'] = implode(' ', $describe);
    }
    if ($mensaje !== null) {
        $atributos['aria-invalid'] = 'true';
    }
    ?>
                <div class="campo<?= $mensaje !== null ? ' campo--error' : '' ?><?= isset($opciones['clase']) ? ' ' . e($opciones['clase']) : '' ?>">
                    <label for="<?= e($nombre) ?>"><?= e($etiqueta) ?></label>
                    <input<?php foreach ($atributos as $clave => $valor): ?> <?= e($clave) ?>="<?= e($valor) ?>"<?php endforeach; ?><?= !empty($opciones['obligatorio']) ? ' required' : '' ?>>
                    <?php if ($pista !== null): ?>
                        <p class="campo__pista" id="pista-<?= e($nombre) ?>"><?= e($pista) ?></p>
                    <?php endif; ?>
                    <?php if ($mensaje !== null): ?>
                        <p class="campo__error" id="error-<?= e($nombre) ?>"><?= e($mensaje) ?></p>
                    <?php endif; ?>
                </div>
    <?php
};

require dirname(__DIR__) . '/parciales/cabecera.php';
?>
<div class="acceso">
    <section class="acceso__escena" aria-labelledby="titulo-acceso">
        <h1 class="acceso__titulo" id="titulo-acceso">Únete</h1>
        <figure class="acceso__foto">
            <img src="/img/productos/dn3707-010.webp" alt="" width="1200" height="855">
        </figure>
    </section>

    <div class="acceso__panel">
        <div class="acceso__caja">
            <p class="acceso__entrada">Una cuenta para comprar, seguir tus pedidos y bordar tu nombre en los pares exclusivos.</p>

            <?php if ($error !== null): ?>
                <p class="acceso__aviso" role="alert">
                    <?= e($errores !== [] ? 'Revisa los campos marcados.' : $error) ?>
                </p>
            <?php endif; ?>

            <form method="post" action="/registro" class="acceso__formulario" data-enviando="Creando cuenta…" novalidate>
                <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                <?php if ($volver !== null): ?>
                    <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <?php endif; ?>

                <div class="acceso__fila">
                    <?php $campo('nombre', 'Nombre', ['maximo' => 100, 'autocompletar' => 'given-name', 'obligatorio' => true]); ?>
                    <?php $campo('apellidos', 'Apellidos', ['maximo' => 150, 'autocompletar' => 'family-name', 'obligatorio' => true]); ?>
                </div>
                <?php $campo('email', 'Email', ['tipo' => 'email', 'maximo' => 150, 'autocompletar' => 'email', 'obligatorio' => true]); ?>
                <?php $campo('password', 'Contraseña', [
                    'tipo' => 'password', 'maximo' => 72, 'autocompletar' => 'new-password', 'obligatorio' => true,
                    'pista' => 'Mínimo 8 caracteres, con letras y números.',
                ]); ?>

                <fieldset class="acceso__grupo">
                    <legend>Datos de envío</legend>
                    <p class="campo__pista acceso__grupo-pista">Opcionales: puedes completarlos después en tu perfil.</p>
                    <?php $campo('telefono', 'Teléfono', ['tipo' => 'tel', 'maximo' => 20, 'autocompletar' => 'tel']); ?>
                    <?php $campo('direccion', 'Dirección', ['maximo' => 200, 'autocompletar' => 'street-address']); ?>
                    <div class="acceso__fila acceso__fila--postal">
                        <?php $campo('codigo_postal', 'Código postal', ['maximo' => 5, 'autocompletar' => 'postal-code', 'modoTeclado' => 'numeric']); ?>
                        <?php $campo('ciudad', 'Ciudad', ['maximo' => 100, 'autocompletar' => 'address-level2']); ?>
                    </div>
                    <?php $campo('provincia', 'Provincia', ['maximo' => 100, 'autocompletar' => 'address-level1']); ?>
                </fieldset>

                <button type="submit" class="boton acceso__enviar">Crear cuenta</button>
            </form>

            <p class="acceso__cruce">¿Ya tienes cuenta? <a href="/login<?= e($sufijoVolver) ?>">Entra</a></p>
        </div>
    </div>
</div>
<script src="/js/acceso.js" defer></script>
<?php require dirname(__DIR__) . '/parciales/pie.php'; ?>
