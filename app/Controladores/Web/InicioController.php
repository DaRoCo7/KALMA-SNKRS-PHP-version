<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Servicios\CatalogoServicio;

/**
 * Portada de la tienda (página HTML).
 */
final class InicioController
{
    /**
     * Las palabras de la casa, en el orden en que cierran la portada.
     * Son texto de marca, no datos: por eso viven aquí y no en la base de datos.
     */
    private const PALABRAS = ['Exclusividad', 'Identidad', 'Pasión'];

    /** Lanzamientos más cercanos que pasan por la cinta de la portada */
    private const LANZAMIENTOS = 3;

    /** Pares a la venta que se enseñan debajo, antes del enlace al catálogo */
    private const DISPONIBLES = 3;

    public function __construct(private readonly CatalogoServicio $catalogo = new CatalogoServicio())
    {
    }

    public function index(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('inicio', [
            'titulo'       => 'Zapatillas exclusivas',
            'palabras'     => self::PALABRAS,
            'lanzamientos' => $this->conFoto($this->catalogo->lanzamientos(self::LANZAMIENTOS)),
            // Se piden de más porque los que no tienen foto se descartan
            'disponibles'  => array_slice($this->conFoto($this->catalogo->novedades(self::DISPONIBLES * 4)), 0, self::DISPONIBLES),
            'opciones'     => $this->catalogo->opcionesPersonalizacion(),
        ]);
    }

    /**
     * Deja solo los pares que ya tienen foto. Una zapatilla que entra por
     * albarán no la tiene hasta que alguien la sube, y en la portada la foto lo
     * es todo: sin ella el par espera en el catálogo.
     * @param list<array> $pares
     * @return list<array>
     */
    private function conFoto(array $pares): array
    {
        $publico = dirname(__DIR__, 3) . '/public';

        return array_values(array_filter($pares, static fn (array $par): bool => is_file($publico . $par['imagen'])));
    }
}
