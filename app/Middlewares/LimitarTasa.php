<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Nucleo\Middleware;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\LimitadorTasa;

/**
 * Limita cuántas veces puede llamar una misma IP a una ruta en una ventana:
 *   new LimitarTasa('registro', 5, 3600)  -> 5 registros por IP y hora
 */
final class LimitarTasa implements Middleware
{
    public function __construct(
        private readonly string $ambito,
        private readonly int $maximo,
        private readonly int $ventanaSegundos,
    ) {
    }

    public function procesar(Peticion $peticion, callable $siguiente): Respuesta
    {
        (new LimitadorTasa())->consumir($this->ambito . ':ip', $peticion->ip, $this->maximo, $this->ventanaSegundos);
        return $siguiente($peticion);
    }
}
