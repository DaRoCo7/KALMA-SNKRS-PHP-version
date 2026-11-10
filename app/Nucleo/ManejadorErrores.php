<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Gestión centralizada de errores.
 *
 * Cualquier error que se produzca en la aplicación termina aquí y se convierte
 * en una respuesta controlada:
 *   - /api/...  -> JSON {"error": {"codigo": 404, "mensaje": "..."}}
 *   - páginas   -> página HTML de error (404 o genérica)
 * Las trazas y los mensajes internos (SQL, rutas de archivos...) solo van al
 * log del contenedor, nunca al navegador.
 */
final class ManejadorErrores
{
    /** Se llama al principio de public/index.php */
    public static function registrar(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        // Los avisos de PHP (variable no definida, división por cero...) se
        // convierten en excepciones para tratarlos igual que cualquier error
        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if (!(error_reporting() & $nivel)) {
                return false;   // respeta el operador @
            }
            throw new \ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });

        // Último recurso para errores que ocurren fuera del router
        set_exception_handler(static function (\Throwable $error): void {
            self::aRespuesta($error, null)->enviar();
        });
    }

    /** Convierte cualquier error en la respuesta que verá el cliente */
    public static function aRespuesta(\Throwable $error, ?Peticion $peticion): Respuesta
    {
        $esApi = $peticion?->esApi() ?? str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api');

        if ($error instanceof HttpExcepcion) {
            // Error esperado: su mensaje está pensado para el cliente
            $estado    = $error->estado;
            $mensaje   = $error->getMessage();
            $detalles  = $error->detalles;
            $cabeceras = $error->cabeceras;
        } else {
            // Error inesperado: al cliente solo le llega un mensaje genérico y una
            // referencia para localizar el detalle en los logs
            $estado    = 500;
            $mensaje   = 'Error interno del servidor';
            $cabeceras = [];
            $referencia = bin2hex(random_bytes(4));

            Log::error('Excepción no controlada', [
                'referencia' => $referencia,
                'tipo'       => $error::class,
                'detalle'    => $error->getMessage(),   // no 'mensaje': esa clave ya la usa Log
                'origen'     => $error->getFile() . ':' . $error->getLine(),
                'ruta'       => $peticion?->ruta,
            ]);

            $detalles = ['referencia' => $referencia];
            if (Entorno::esDesarrollo()) {
                $detalles['depuracion'] = $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')';
            }
        }

        try {
            if ($esApi) {
                $cuerpo = ['codigo' => $estado, 'mensaje' => $mensaje];
                if ($detalles !== []) {
                    $cuerpo['detalles'] = $detalles;
                }
                $respuesta = Respuesta::json(['error' => $cuerpo], $estado);
            } else {
                $plantilla = $estado === 404 ? 'errores/404' : 'errores/error';
                $respuesta = Respuesta::vista($plantilla, [
                    'estado'     => $estado,
                    'mensaje'    => $mensaje,
                    'referencia' => $detalles['referencia'] ?? null,
                ], $estado);
            }
        } catch (\Throwable) {
            // Si falla hasta la página de error, se responde con texto plano
            $respuesta = new Respuesta('Error interno del servidor', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        foreach ($cabeceras as $nombre => $valor) {
            $respuesta->conCabecera($nombre, $valor);
        }

        return $respuesta;
    }
}
