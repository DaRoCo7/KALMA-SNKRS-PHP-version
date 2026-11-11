<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Repositorios\LimiteRepositorio;

/**
 * Rate limiting en la aplicación (segunda barrera; la primera es Nginx).
 *
 * Cuenta intentos por "ámbito + identificador" en una ventana de tiempo fija:
 *   consumir('login:email', 'ana@x.com', 5, 900) -> máximo 5 intentos cada 15 min
 * El contador vive en PostgreSQL y se incrementa con una sola sentencia atómica,
 * así que dos peticiones simultáneas no pueden colarse a la vez.
 */
final class LimitadorTasa
{
    public function __construct(
        private readonly LimiteRepositorio $limites = new LimiteRepositorio(),
    ) {
    }

    /** @throws HttpExcepcion 429 con Retry-After si se supera el máximo */
    public function consumir(string $ambito, string $identificador, int $maximo, int $ventanaSegundos): void
    {
        $estado = $this->limites->incrementar($ambito, $this->clave($ambito, $identificador), $ventanaSegundos);

        if ($estado['contador'] > $maximo) {
            Log::aviso('Límite de peticiones superado', ['ambito' => $ambito, 'intentos' => $estado['contador']]);
            throw HttpExcepcion::demasiadasPeticiones(max(1, $estado['reinicio'] - time()));
        }
    }

    /** Pone el contador a cero (ej. tras un login correcto) */
    public function reiniciar(string $ambito, string $identificador): void
    {
        $this->limites->borrar($this->clave($ambito, $identificador));
    }

    /** La IP o el email nunca se guardan en claro: solo su hash */
    private function clave(string $ambito, string $identificador): string
    {
        return hash('sha256', $ambito . '|' . mb_strtolower($identificador));
    }
}
