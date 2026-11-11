<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Respuesta HTTP que devuelve cada controlador.
 *
 * Los controladores nunca hacen echo ni header(): construyen una Respuesta y
 * el front controller la envía. Así el formato (HTML o JSON) se decide en un
 * único sitio.
 */
final class Respuesta
{
    /** Cabeceras de seguridad que llevan todas las respuestas */
    private const CABECERAS_SEGURIDAD = [
        'X-Content-Type-Options' => 'nosniff',   // el navegador no "adivina" el tipo de archivo
        'X-Frame-Options'        => 'DENY',      // nadie puede incrustar la web en un iframe
        'Referrer-Policy'        => 'same-origin',
    ];

    /** @param array<string, string> $cabeceras */
    public function __construct(
        private readonly string $cuerpo = '',
        private readonly int $estado = 200,
        private array $cabeceras = [],
    ) {
    }

    /** Respuesta de la API. Convención: éxito -> {"datos": ...}, error -> {"error": {...}} */
    public static function json(mixed $datos, int $estado = 200): self
    {
        $cuerpo = json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

        return new self($cuerpo, $estado, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',   // datos privados: que no se guarden en caché
        ]);
    }

    public static function html(string $html, int $estado = 200): self
    {
        return new self($html, $estado, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Página HTML a partir de una plantilla de app/vistas */
    public static function vista(string $plantilla, array $datos = [], int $estado = 200): self
    {
        return self::html(Vista::renderizar($plantilla, $datos), $estado);
    }

    /**
     * Archivo para descargar (exportaciones en CSV o JSON): el navegador lo
     * guarda con ese nombre en vez de mostrarlo. El nombre lo pone siempre el
     * servidor (nunca el usuario), así no se puede inyectar nada en la cabecera.
     */
    public static function descarga(string $contenido, string $tipo, string $nombreArchivo): self
    {
        return new self($contenido, 200, [
            'Content-Type'        => $tipo,
            'Content-Disposition' => 'attachment; filename="' . $nombreArchivo . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** 303: tras enviar un formulario, el navegador pasa a la nueva página con GET */
    public static function redireccion(string $url, int $estado = 303): self
    {
        return new self('', $estado, ['Location' => $url]);
    }

    public static function sinContenido(): self
    {
        return new self('', 204);
    }

    public function conCabecera(string $nombre, string $valor): self
    {
        $this->cabeceras[$nombre] = $valor;
        return $this;
    }

    public function enviar(): void
    {
        http_response_code($this->estado);
        foreach ($this->cabeceras + self::CABECERAS_SEGURIDAD as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
        if ($this->estado !== 204) {
            echo $this->cuerpo;
        }
    }
}
