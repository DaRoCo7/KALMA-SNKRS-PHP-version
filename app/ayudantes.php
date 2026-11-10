<?php

declare(strict_types=1);

/*
 * Funciones de ayuda globales para las vistas.
 * Composer las carga siempre (autoload "files" en composer.json).
 */

if (!function_exists('e')) {
    /**
     * Escapa un texto antes de imprimirlo en HTML (protección contra XSS).
     * <script> se convierte en &lt;script&gt; y el navegador lo muestra como texto.
     */
    function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
