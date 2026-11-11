<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Error "esperado" que debe llegar al cliente con un código HTTP concreto.
 *
 * Los servicios y controladores la lanzan (ej. HttpExcepcion::conflicto(...)) y
 * el ManejadorErrores la convierte en página HTML o en JSON. Su mensaje SÍ se
 * muestra al cliente, así que nunca debe llevar datos internos.
 */
final class HttpExcepcion extends \RuntimeException
{
    /**
     * @param array<string, mixed>  $detalles   Información extra para el cliente (ej. errores de validación)
     * @param array<string, string> $cabeceras  Cabeceras HTTP adicionales (ej. Allow en un 405)
     */
    public function __construct(
        public readonly int $estado,
        string $mensaje,
        public readonly array $detalles = [],
        public readonly array $cabeceras = [],
    ) {
        parent::__construct($mensaje, $estado);
    }

    /** 400: datos que no pasan la validación. $errores = ['campo' => 'motivo'] */
    public static function peticionIncorrecta(string $mensaje = 'Petición incorrecta', array $errores = []): self
    {
        return new self(400, $mensaje, $errores === [] ? [] : ['errores' => $errores]);
    }

    /** 401: hace falta iniciar sesión */
    public static function noAutenticado(string $mensaje = 'Debes iniciar sesión'): self
    {
        return new self(401, $mensaje);
    }

    /** 403: con sesión iniciada, pero sin permiso para esta operación */
    public static function prohibido(string $mensaje = 'No tienes permiso para esta operación'): self
    {
        return new self(403, $mensaje);
    }

    /** 404: la ruta o el recurso no existen */
    public static function noEncontrado(string $mensaje = 'Recurso no encontrado'): self
    {
        return new self(404, $mensaje);
    }

    /** 405: la ruta existe pero no con este método (ej. DELETE en una ruta de solo lectura) */
    public static function metodoNoPermitido(array $permitidos): self
    {
        $permitidos = implode(', ', array_unique($permitidos));
        return new self(405, 'Método no permitido en esta ruta', ['permitidos' => $permitidos], ['Allow' => $permitidos]);
    }

    /** 409: la operación choca con el estado actual (sin stock, pedido ya enviado...) */
    public static function conflicto(string $mensaje, array $detalles = []): self
    {
        return new self(409, $mensaje, $detalles);
    }

    /** 429: se ha superado el límite de intentos. Retry-After indica cuándo reintentar */
    public static function demasiadasPeticiones(int $segundosEspera): self
    {
        $minutos = (int) ceil($segundosEspera / 60);
        return new self(
            429,
            "Demasiados intentos. Vuelve a intentarlo dentro de {$minutos} min",
            ['reintentar_en_segundos' => $segundosEspera],
            ['Retry-After' => (string) $segundosEspera]
        );
    }

    /** 413: cuerpo de la petición demasiado grande */
    public static function demasiadoGrande(): self
    {
        return new self(413, 'El cuerpo de la petición es demasiado grande');
    }

    /** 415: formato del cuerpo no aceptado */
    public static function tipoNoSoportado(string $mensaje): self
    {
        return new self(415, $mensaje);
    }
}
