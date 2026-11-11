<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\HttpExcepcion;

/**
 * Comprueba y prepara las líneas de un pedido nuevo, en dos pasos:
 *
 *   1. validar():  la FORMA de los datos que envía el navegador (sin tocar la BD).
 *   2. preparar(): los datos contra la BD, con las tallas ya bloqueadas: que la
 *      talla exista y esté a la venta, que haya stock, que la personalización
 *      cumpla las reglas, y el precio y el recargo de cada línea.
 *
 * El precio y el recargo salen siempre del catálogo, nunca del navegador.
 * Los errores usan claves tipo "lineas.0.cantidad" para que el front pueda
 * pintar cada mensaje junto a su línea.
 */
final class LineasPedido
{
    public const MAX_LINEAS = 20;
    public const MAX_CANTIDAD = 20;               // igual que el CHECK de lineas_pedido
    private const MAX_OPCIONES = 4;               // hay 4 tipos y solo se admite una opción de cada tipo
    private const MAX_TEXTO = 30;                 // tamaño de linea_personalizacion.texto
    private const MAX_ID = 2_147_483_647;         // máximo de un INTEGER de PostgreSQL

    /**
     * Paso 1: lista de 1 a 20 líneas, ids y cantidades enteros, sin tallas
     * repetidas, y personalización opcional: [{opcion_id, texto?}].
     *
     * @param array<string, string> $errores se le añaden los errores encontrados
     * @return list<array{variante_id: int, cantidad: int, personalizacion: list<array{opcion_id: int, texto: ?string}>}>
     */
    public function validar(mixed $valor, array &$errores): array
    {
        if (!is_array($valor) || !array_is_list($valor) || $valor === []) {
            $errores['lineas'] = 'Debe ser una lista con al menos una línea';
            return [];
        }
        if (count($valor) > self::MAX_LINEAS) {
            $errores['lineas'] = 'Máximo ' . self::MAX_LINEAS . ' líneas por pedido';
            return [];
        }

        $lineas = [];
        $tallasVistas = [];
        foreach ($valor as $i => $linea) {
            $campo = "lineas.{$i}";
            if (!is_array($linea)) {
                $errores[$campo] = 'Debe ser un objeto';
                continue;
            }

            $varianteId = self::entero($linea['variante_id'] ?? null, 1, self::MAX_ID);
            $cantidad = self::entero($linea['cantidad'] ?? null, 1, self::MAX_CANTIDAD);

            if ($varianteId === null) {
                $errores["{$campo}.variante_id"] = 'Identificador numérico de la talla';
            } elseif (isset($tallasVistas[$varianteId])) {
                // La tabla solo admite una línea por talla y pedido (UNIQUE pedido + variante)
                $errores["{$campo}.variante_id"] = 'Talla repetida: junta las unidades en una sola línea';
            }
            if ($cantidad === null) {
                $errores["{$campo}.cantidad"] = 'Número entero entre 1 y ' . self::MAX_CANTIDAD;
            }
            $personalizacion = $this->validarPersonalizacion($linea['personalizacion'] ?? [], $campo, $errores);

            if ($varianteId !== null && $cantidad !== null) {
                $tallasVistas[$varianteId] = true;
                $lineas[] = ['variante_id' => $varianteId, 'cantidad' => $cantidad, 'personalizacion' => $personalizacion];
            }
        }
        return $lineas;
    }

    /**
     * Paso 2: cada línea contra la BD. Debe llamarse dentro de la transacción y
     * con las tallas ya bloqueadas (StockRepositorio::bloquearVariantes), para que
     * el stock que se comprueba aquí sea el mismo que luego se descuenta.
     *
     * @param array<int, array> $variantes tallas bloqueadas, indexadas por id
     * @param array<int, array> $opciones  opciones de personalización activas, indexadas por id
     * @throws HttpExcepcion 400 talla u opción no válidas · 409 stock insuficiente
     */
    public function preparar(array $lineas, array $variantes, array $opciones): array
    {
        $errores = [];
        $sinStock = [];
        $preparadas = [];

        foreach ($lineas as $i => $linea) {
            $variante = $variantes[$linea['variante_id']] ?? null;
            if ($variante === null || !$variante['activo'] || !$variante['producto_activo'] || !$variante['lanzado']) {
                $errores["lineas.{$i}.variante_id"] = 'Esta talla no existe o no está a la venta';
                continue;
            }

            [$personalizacion, $recargo] = $this->precioPersonalizacion(
                $linea['personalizacion'], $opciones, (bool) $variante['personalizable'],
                "lineas.{$i}.personalizacion", $errores
            );

            if ((int) $variante['stock'] < $linea['cantidad']) {
                $sinStock[] = [
                    'linea'       => $i,
                    'variante_id' => $linea['variante_id'],
                    'producto'    => "{$variante['nombre']} {$variante['colorway']}",
                    'talla'       => (float) $variante['talla'],
                    'solicitado'  => $linea['cantidad'],
                    'disponible'  => (int) $variante['stock'],
                ];
            }

            // Copia de los datos del catálogo en el momento de la compra
            $preparadas[] = [
                'variante_id'             => $linea['variante_id'],
                'nombre_producto'         => $variante['nombre'],
                'talla'                   => $variante['talla'],
                'color'                   => $variante['colorway'],
                'cantidad'                => $linea['cantidad'],
                'precio_unitario'         => $variante['precio'],
                'recargo_personalizacion' => $recargo,
                'personalizacion'         => $personalizacion,
            ];
        }

        // Primero los datos mal formados (400) y después la falta de stock (409)
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Hay datos no válidos', $errores);
        }
        if ($sinStock !== []) {
            throw HttpExcepcion::conflicto('No hay stock suficiente para alguna de las tallas', ['sin_stock' => $sinStock]);
        }
        return $preparadas;
    }

    /** @return list<array{opcion_id: int, texto: ?string}> */
    private function validarPersonalizacion(mixed $valor, string $campoLinea, array &$errores): array
    {
        $campo = "{$campoLinea}.personalizacion";
        if ($valor === null || $valor === []) {
            return [];
        }
        if (!is_array($valor) || !array_is_list($valor) || count($valor) > self::MAX_OPCIONES) {
            $errores[$campo] = 'Lista de hasta ' . self::MAX_OPCIONES . ' opciones';
            return [];
        }

        $elegidas = [];
        foreach ($valor as $j => $opcion) {
            $opcionId = is_array($opcion) ? self::entero($opcion['opcion_id'] ?? null, 1, self::MAX_ID) : null;
            $texto = is_array($opcion) && is_string($opcion['texto'] ?? null) ? trim($opcion['texto']) : null;

            if ($opcionId === null) {
                $errores["{$campo}.{$j}.opcion_id"] = 'Identificador numérico de la opción';
            } elseif (isset($elegidas[$opcionId])) {
                $errores["{$campo}.{$j}.opcion_id"] = 'Opción repetida en la misma línea';
            } elseif ($texto !== null && mb_strlen($texto) > self::MAX_TEXTO) {
                $errores["{$campo}.{$j}.texto"] = 'Máximo ' . self::MAX_TEXTO . ' caracteres';
            } else {
                $elegidas[$opcionId] = ['opcion_id' => $opcionId, 'texto' => $texto === '' ? null : $texto];
            }
        }
        return array_values($elegidas);
    }

    /**
     * Opciones elegidas en una línea y su recargo por par.
     *
     * @return array{0: list<array{opcion_id: int, texto: ?string, recargo: float}>, 1: float}
     */
    private function precioPersonalizacion(
        array $elegidas,
        array $opciones,
        bool $personalizable,
        string $campo,
        array &$errores,
    ): array {
        $resultado = [];
        $recargo = 0.0;
        $tipos = [];

        foreach ($elegidas as $j => $eleccion) {
            $opcion = $opciones[$eleccion['opcion_id']] ?? null;
            $error = $this->errorDeOpcion($opcion, $eleccion['texto'], $tipos, $personalizable);
            if ($error !== null) {
                $errores["{$campo}.{$j}.{$error[0]}"] = $error[1];
                continue;
            }

            $tipos[$opcion['tipo']] = true;
            $recargo += (float) $opcion['recargo'];
            $resultado[] = [
                'opcion_id' => (int) $opcion['id'],
                // Solo se guarda texto en las opciones que lo usan (el bordado)
                'texto'     => $opcion['max_caracteres'] === null ? null : $eleccion['texto'],
                'recargo'   => (float) $opcion['recargo'],
            ];
        }
        return [$resultado, $recargo];
    }

    /**
     * Reglas de una opción elegida. match(true) prueba las condiciones en orden
     * y se queda con la primera que se cumple: si la opción no existe, las
     * siguientes ni se evalúan.
     *
     * @return array{0: string, 1: string}|null [campo, mensaje] o null si es válida
     */
    private function errorDeOpcion(?array $opcion, ?string $texto, array $tiposElegidos, bool $personalizable): ?array
    {
        $maximo = $opcion['max_caracteres'] ?? null;

        return match (true) {
            // No existe o está desactivada (la caja de madera agotada)
            $opcion === null                                  => ['opcion_id', 'Opción de personalización no disponible'],
            // No hay dos cajas ni dos colores de cordones en la misma línea
            isset($tiposElegidos[$opcion['tipo']])            => ['opcion_id', 'Solo una opción de cada tipo por línea'],
            // El bordado modifica la zapatilla: las Jordan y las Air Max no se bordan
            $opcion['afecta_zapatilla'] && !$personalizable   => ['opcion_id', "Este modelo no admite «{$opcion['nombre']}»"],
            $maximo !== null && $texto === null               => ['texto', "Obligatorio para «{$opcion['nombre']}»"],
            $maximo !== null && mb_strlen($texto) > $maximo   => ['texto', "Máximo {$maximo} caracteres"],
            $maximo !== null && !preg_match('/^[\p{L} ]+$/u', $texto) => ['texto', 'Solo letras y espacios'],
            default                                           => null,
        };
    }

    /** Entero dentro de un rango, o null. true/false o un array no son números */
    private static function entero(mixed $valor, int $minimo, int $maximo): ?int
    {
        if (is_bool($valor) || is_array($valor) || $valor === null) {
            return null;
        }
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimo, 'max_range' => $maximo]]);
        return $entero === false ? null : $entero;
    }
}
