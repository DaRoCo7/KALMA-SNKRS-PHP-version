<?php
/**
 * Inicio de sesión. Pantalla partida: a un lado el rótulo y una zapatilla sobre
 * negro, al otro el formulario sobre blanco.
 *
 * @var string                $email    email escrito antes (para no tener que repetirlo)
 * @var string|null           $error    mensaje general
 * @var array<string, string> $errores  campo => motivo (solo si faltan datos)
 * @var string|null           $volver   ruta interna a la que volver tras entrar
 */
$titulo = 'Iniciar sesión';
$estilos = ['/css/acceso.css'];
$claseMain = '';   // la pantalla partida ocupa todo el ancho
$errores ??= [];
$volver ??= null;

// El enlace al registro conserva la vuelta: quien cambia de idea no pierde su sitio
$sufijoVolver = $volver !== null ? '?volver=' . rawurlencode($volver) : '';

require dirname(__DIR__) . '/parciales/cabecera.php';
?>
<div class="acceso">
    <section class="acceso__escena" aria-labelledby="titulo-acceso">
        <h1 class="acceso__titulo" id="titulo-acceso">Entra</h1>
        <figure class="acceso__foto">
            <img src="/img/productos/dz5485-612.webp" alt="" width="1200" height="730">
        </figure>
    </section>

    <div class="acceso__panel">
        <div class="acceso__caja">
            <p class="acceso__entrada">Tus pedidos, tus tallas y tus pares personalizados, donde los dejaste.</p>

            <?php if ($error !== null): ?>
                <p class="acceso__aviso" id="aviso-acceso" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <form method="post" action="/login" class="acceso__formulario" data-enviando="Entrando…" novalidate>
                <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                <?php if ($volver !== null): ?>
                    <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <?php endif; ?>

                <?php
                // Si el error es de credenciales, los dos campos están mal a la vez y
                // no se sabe cuál: los dos apuntan al aviso general
                $credenciales = $error !== null && $errores === [];
                foreach ([
                    ['email', 'Email', 'email', 150, 'email', $email],
                    ['password', 'Contraseña', 'password', 72, 'current-password', ''],
                ] as [$nombre, $etiqueta, $tipo, $maximo, $autocompletar, $valor]):
                    $mensaje = $errores[$nombre] ?? null;
                    $describe = $mensaje !== null ? 'error-' . $nombre : ($credenciales ? 'aviso-acceso' : null);
                ?>
                <div class="campo<?= $mensaje !== null ? ' campo--error' : '' ?>">
                    <label for="<?= e($nombre) ?>"><?= e($etiqueta) ?></label>
                    <input id="<?= e($nombre) ?>" name="<?= e($nombre) ?>" type="<?= e($tipo) ?>"
                           maxlength="<?= $maximo ?>" autocomplete="<?= e($autocompletar) ?>" required
                           value="<?= e($valor) ?>"
                           <?= $tipo === 'email' ? 'spellcheck="false" autocapitalize="none"' : '' ?>
                           <?= $describe !== null ? 'aria-describedby="' . e($describe) . '"' : '' ?>
                           <?= $mensaje !== null || $credenciales ? 'aria-invalid="true"' : '' ?>
                           <?= $credenciales && $nombre === 'password' ? 'data-foco' : '' ?>>
                    <?php if ($mensaje !== null): ?>
                        <p class="campo__error" id="error-<?= e($nombre) ?>"><?= e($mensaje) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="boton acceso__enviar">Entrar</button>
            </form>

            <p class="acceso__cruce">¿Primera vez en KALMA? <a href="/registro<?= e($sufijoVolver) ?>">Crea tu cuenta</a></p>
        </div>
    </div>
</div>
<script src="/js/acceso.js" defer></script>
<?php require dirname(__DIR__) . '/parciales/pie.php'; ?>
