<?php
/**
 * Inicio de sesión en una pantalla mínima: sin cabecera ni pie, solo el logo
 * arriba (que lleva a la portada) y el formulario debajo, en una columna
 * centrada. Entrar es un trámite y la página no ofrece nada que distraiga.
 *
 * @var string                $email    email escrito antes (para no tener que repetirlo)
 * @var string|null           $error    mensaje general
 * @var array<string, string> $errores  campo => motivo (solo si faltan datos)
 * @var string|null           $volver   ruta interna a la que volver tras entrar
 */
$titulo = 'Iniciar sesión';
$estilos = ['/css/login.css'];
$claseBody = 'pagina-login';
$errores ??= [];
$volver ??= null;

// El enlace al registro conserva la vuelta: quien cambia de idea no pierde su sitio
$sufijoVolver = $volver !== null ? '?volver=' . rawurlencode($volver) : '';

require dirname(__DIR__) . '/parciales/documento-inicio.php';
?>
<div class="login">
    <a class="login__marca" href="/">
        <img src="/img/logo-kalma.png" alt="KALMA SNKRS, volver a la portada" width="640" height="275">
    </a>

    <h1 class="login__titulo">Inicia sesión</h1>

    <?php if ($error !== null): ?>
        <p class="login__aviso" id="aviso-acceso" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php // acceso__formulario es el gancho de acceso.js: foco al primer error y formato del email ?>
    <form method="post" action="/login" class="login__formulario acceso__formulario" data-enviando="Entrando…" novalidate>
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

        <button type="submit" class="boton boton--claro login__enviar">Entrar</button>
    </form>

    <p class="login__cruce">¿Primera vez en KALMA? <a href="/registro<?= e($sufijoVolver) ?>">Crea tu cuenta</a></p>
</div>
<script src="/js/acceso.js" defer></script>
<?php require dirname(__DIR__) . '/parciales/documento-fin.php'; ?>
