<?php
/**
 * Página del catálogo.
 * @var string $titulo
 */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> | KALMA SNKRS</title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
    <header class="cabecera">
        <div class="contenedor">
            <a class="marca" href="/">KALMA SNKRS</a>
        </div>
    </header>

    <main class="contenedor">
        <h1><?= e($titulo) ?></h1>
    </main>
</body>
</html>
