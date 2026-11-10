<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Motor de plantillas mínimo: ejecuta un archivo PHP de app/vistas con unas
 * variables y devuelve el HTML generado como texto.
 *
 * En las plantillas, todo dato que venga del usuario o de la base de datos se
 * imprime con e($valor) para evitar XSS.
 */
final class Vista
{
    public static function renderizar(string $plantilla, array $datos = []): string
    {
        $archivo = dirname(__DIR__) . '/vistas/' . $plantilla . '.php';
        if (!is_file($archivo)) {
            throw new \RuntimeException("No existe la vista {$plantilla}");
        }

        // ['titulo' => 'Catálogo'] -> la plantilla puede usar $titulo
        extract($datos, EXTR_SKIP);

        ob_start();
        try {
            require $archivo;
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();   // no se envía media página
            throw $error;
        }
    }
}
