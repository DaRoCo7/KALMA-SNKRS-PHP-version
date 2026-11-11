<?php
/**
 * Página de error genérica (400, 403, 405, 409, 500...).
 * Solo muestra el mensaje pensado para el usuario y, en los 500, una
 * referencia para localizar el error en los logs. Nunca trazas.
 *
 * @var int $estado
 * @var string $mensaje
 * @var string|null $referencia
 */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error <?= e($estado) ?> | KALMA SNKRS</title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
    <main class="contenedor">
        <h1>Algo no ha ido bien</h1>
        <p><?= e($mensaje) ?></p>
        <?php if ($referencia !== null): ?>
            <p>Referencia del error: <code><?= e($referencia) ?></code></p>
        <?php endif; ?>
        <a href="/catalogo">Volver al catálogo</a>
    </main>
</body>
</html>
