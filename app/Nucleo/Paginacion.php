<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Paginación de listados: ?pagina=2&por_pagina=10
 *
 * Los listados nunca devuelven todas las filas de golpe: la consulta usa
 * LIMIT/OFFSET y la respuesta incluye los datos para pintar el paginador.
 * por_pagina tiene un máximo para que nadie pida 1.000.000 de filas.
 */
final class Paginacion
{
    public function __construct(
        public readonly int $pagina,
        public readonly int $porPagina,
    ) {
    }

    /** @throws HttpExcepcion 400 si los parámetros no son enteros válidos */
    public static function desdePeticion(Peticion $peticion, int $porDefecto = 10, int $maximo = 50): self
    {
        $pagina = filter_var($peticion->query['pagina'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $porPagina = filter_var($peticion->query['por_pagina'] ?? $porDefecto, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $maximo],
        ]);

        if ($pagina === false || $porPagina === false) {
            throw HttpExcepcion::peticionIncorrecta('Parámetros de paginación no válidos', [
                'pagina'     => 'Número entero desde 1',
                'por_pagina' => "Número entero entre 1 y {$maximo}",
            ]);
        }

        return new self($pagina, $porPagina);
    }

    /** Filas que hay que saltarse: página 3 de 10 en 10 -> OFFSET 20 */
    public function offset(): int
    {
        return ($this->pagina - 1) * $this->porPagina;
    }

    /** Datos del paginador que acompañan al listado */
    public function meta(int $total): array
    {
        return [
            'pagina'     => $this->pagina,
            'por_pagina' => $this->porPagina,
            'total'      => $total,
            'paginas'    => (int) ceil($total / $this->porPagina),
        ];
    }
}
