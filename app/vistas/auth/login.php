<?php
/**
 * Formulario de inicio de sesión.
 * @var string $email     email escrito antes (para no tener que repetirlo)
 * @var string|null $error
 */
$titulo = 'Iniciar sesión';
require dirname(__DIR__) . '/parciales/cabecera.php';
?>
        <h1>Iniciar sesión</h1>

        <?php if ($error !== null): ?>
            <p class="aviso-error"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="/login" class="formulario">
            <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="150" required value="<?= e($email) ?>">

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" maxlength="72" required>

            <button type="submit">Entrar</button>
        </form>

        <p>¿No tienes cuenta? <a href="/registro">Crea una aquí</a>.</p>
<?php require dirname(__DIR__) . '/parciales/pie.php'; ?>
