<?php
/**
 * Formulario de registro de clientes.
 * @var array<string, string> $datos    lo que escribió el usuario (sin contraseña)
 * @var array<string, string> $errores  campo => motivo
 * @var string|null $error              mensaje general
 */
$titulo = 'Crear cuenta';
require dirname(__DIR__) . '/parciales/cabecera.php';

/** Pinta un campo de texto con su etiqueta, su valor anterior y su error */
$campo = static function (string $nombre, string $etiqueta, string $tipo = 'text', int $maximo = 100, bool $obligatorio = false)
    use ($datos, $errores): void {
    ?>
            <label for="<?= e($nombre) ?>"><?= e($etiqueta) ?><?= $obligatorio ? ' *' : '' ?></label>
            <input id="<?= e($nombre) ?>" name="<?= e($nombre) ?>" type="<?= e($tipo) ?>" maxlength="<?= $maximo ?>"
                   value="<?= $tipo === 'password' ? '' : e($datos[$nombre] ?? '') ?>"<?= $obligatorio ? ' required' : '' ?>>
            <?php if (isset($errores[$nombre])): ?>
                <small class="aviso-error"><?= e($errores[$nombre]) ?></small>
            <?php endif; ?>
    <?php
};
?>
        <h1>Crear cuenta</h1>

        <?php if ($error !== null): ?>
            <p class="aviso-error"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="/registro" class="formulario">
            <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">

            <?php $campo('nombre', 'Nombre', 'text', 100, true); ?>
            <?php $campo('apellidos', 'Apellidos', 'text', 150, true); ?>
            <?php $campo('email', 'Email', 'email', 150, true); ?>
            <?php $campo('password', 'Contraseña (mínimo 8 caracteres, con letras y números)', 'password', 72, true); ?>

            <?php $campo('nif', 'DNI o NIE', 'text', 15); ?>
            <?php $campo('telefono', 'Teléfono', 'tel', 20); ?>
            <?php $campo('direccion', 'Dirección', 'text', 200); ?>
            <?php $campo('codigo_postal', 'Código postal', 'text', 5); ?>
            <?php $campo('ciudad', 'Ciudad', 'text', 100); ?>
            <?php $campo('provincia', 'Provincia', 'text', 100); ?>

            <button type="submit">Crear cuenta</button>
        </form>

        <p>¿Ya tienes cuenta? <a href="/login">Inicia sesión</a>.</p>
<?php require dirname(__DIR__) . '/parciales/pie.php'; ?>
