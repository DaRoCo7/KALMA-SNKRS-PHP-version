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
 *   consumir('registro:ip', '203.0.113.7', 5, 3600) -> máximo 5 registros por hora
 * El contador vive en PostgreSQL y se incrementa con una sola sentencia atómica,
 * así que dos peticiones simultáneas no pueden colarse a la vez.
 */
final class LimitadorTasa
{
    public function __construct(
        private readonly LimiteRepositorio $limites = new LimiteRepositorio(),
    ) {
    }

    /**
     * Suma un intento y corta la petición si pasa del máximo.
     *
     * @return int intentos que lleva en la ventana, contando este (para reglas más finas)
     * @throws HttpExcepcion 429 con Retry-After si se supera el máximo
     */
    public function consumir(string $ambito, string $identificador, int $maximo, int $ventanaSegundos): int
    {
        $estado = $this->limites->incrementar($ambito, $this->clave($ambito, $identificador), $ventanaSegundos);

        if ($estado['contador'] > $maximo) {
            Log::aviso('Límite de peticiones superado', ['ambito' => $ambito, 'intentos' => $estado['contador']]);
            throw HttpExcepcion::demasiadasPeticiones(max(1, $estado['reinicio'] - time()));
        }
        return $estado['contador'];
    }

    /**
     * Suma un intento sin cortar nada (ej. un login fallido): sirve para contar
     * hechos que otra regla consulta después con intentos().
     */
    public function anotar(string $ambito, string $identificador, int $ventanaSegundos): void
    {
        $this->limites->incrementar($ambito, $this->clave($ambito, $identificador), $ventanaSegundos);
    }

    /** Intentos que lleva en la ventana actual, sin sumar ninguno */
    public function intentos(string $ambito, string $identificador, int $ventanaSegundos): int
    {
        return $this->limites->contador($this->clave($ambito, $identificador), $ventanaSegundos);
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
