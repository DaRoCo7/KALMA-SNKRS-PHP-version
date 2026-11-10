<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Repositorios\ProductoRepositorio;

/**
 * Reglas del catálogo público: qué filtros se aceptan y qué datos de cada
 * producto se publican (nunca el RFID ni los costes internos).
 */
final class CatalogoServicio
{
    public function __construct(private readonly ProductoRepositorio $productos = new ProductoRepositorio())
    {
    }

    /**
     * Listado filtrado y paginado.
     * @return array{datos: list<array>, paginacion: array, filtros: array}
     */
    public function listar(array $query, Paginacion $paginacion): array
    {
        $filtros = $this->validarFiltros($query);
        $orden = $filtros['orden'];
        unset($filtros['orden']);

        $total = $this->productos->contar($filtros);
        $filas = $this->productos->buscar($filtros, $orden, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map($this->resumen(...), $filas),
            'paginacion' => $paginacion->meta($total),
            'filtros'    => $filtros + ['orden' => $orden],   // los filtros aplicados, para la interfaz
        ];
    }

    /** Ficha completa de un producto */
    public function ver(string $slug): array
    {
        $producto = $this->productos->buscarPorSlug($slug) ?? throw HttpExcepcion::noEncontrado('Producto no encontrado');
        $id = (int) $producto['id'];

        $ficha = $this->resumen($producto);
        $ficha['descripcion'] = $producto['descripcion'];
        $ficha['tallas'] = array_map(static fn (array $t): array => [
            'talla'      => (float) $t['talla'],
            'stock'      => (int) $t['stock'],
            'disponible' => (int) $t['stock'] > 0,
        ], $this->productos->tallas($id));
        $ficha['stock_total'] = array_sum(array_column($ficha['tallas'], 'stock'));
        unset($ficha['tallas_disponibles']);

        $ficha['otros_colores'] = array_map(static fn (array $c): array => [
            'slug'         => $c['slug'],
            'colorway'     => $c['colorway'],
            'codigo_marca' => $c['codigo_marca'],
            'color'        => ['nombre' => $c['color'], 'hex' => $c['codigo_hex']],
            'imagen'       => '/' . $c['imagen'],
        ], $this->productos->otrosColores($id, $producto['codigo_marca']));

        $ficha['opciones_personalizacion'] = $producto['personalizable']
            ? array_map(static fn (array $o): array => [
                'id'             => (int) $o['id'],
                'tipo'           => $o['tipo'],
                'nombre'         => $o['nombre'],
                'requiere_texto' => $o['requiere_texto'],
                'recargo'        => (float) $o['recargo'],
            ], $this->productos->opcionesPersonalizacion())
            : [];

        return $ficha;
    }

    public function valoresFiltros(): array
    {
        $valores = $this->productos->valoresFiltros();

        return [
            'categorias' => array_map(static fn (array $c): array => [
                'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'productos' => (int) $c['productos'],
            ], $valores['categorias']),
            'colores' => array_map(static fn (array $c): array => [
                'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'hex' => $c['codigo_hex'],
            ], $valores['colores']),
            'tallas'  => array_map('floatval', $valores['tallas']),
            'precio'  => ['minimo' => (float) $valores['precio']['minimo'], 'maximo' => (float) $valores['precio']['maximo']],
            'ordenes' => array_keys(ProductoRepositorio::ORDENES),
        ];
    }

    /** Datos de un producto en el listado (PostgreSQL devuelve los NUMERIC como texto) */
    private function resumen(array $p): array
    {
        return [
            'id'                 => (int) $p['id'],
            'nombre'             => $p['nombre'],
            'colorway'           => $p['colorway'],
            'codigo_marca'       => $p['codigo_marca'],
            'slug'               => $p['slug'],
            'precio'             => (float) $p['precio'],
            'personalizable'     => $p['personalizable'],
            'imagen'             => '/' . $p['imagen'],
            'categoria'          => ['id' => (int) $p['categoria_id'], 'nombre' => $p['categoria']],
            'color'              => ['nombre' => $p['color'], 'hex' => $p['codigo_hex']],
            'stock_total'        => (int) ($p['stock_total'] ?? 0),
            'tallas_disponibles' => array_map('floatval', json_decode($p['tallas'] ?? '[]', true)),
        ];
    }

    /**
     * ?buscar=jordan&categoria=3&color=4&talla=42.5&precio_min=100&precio_max=200
     *  &personalizable=1&disponible=1&orden=precio_asc
     * @throws HttpExcepcion 400 con todos los parámetros no válidos
     */
    private function validarFiltros(array $query): array
    {
        // Cada filtro: cómo se convierte (null = no válido) y qué mensaje se devuelve si falla
        $reglas = [
            'buscar'         => [$this->texto(...), 'Texto de hasta 60 caracteres'],
            'categoria'      => [$this->identificador(...), 'Identificador numérico'],
            'color'          => [$this->identificador(...), 'Identificador numérico'],
            'talla'          => [$this->talla(...), 'Talla entre 34 y 50 (admite medias tallas, ej. 42.5)'],
            'precio_min'     => [$this->precio(...), 'Número mayor o igual que 0'],
            'precio_max'     => [$this->precio(...), 'Número mayor o igual que 0'],
            'personalizable' => [$this->booleano(...), 'Valores: 1/0 o true/false'],
            'disponible'     => [$this->booleano(...), 'Valores: 1/0 o true/false'],
        ];

        $filtros = [];
        $errores = [];
        foreach ($reglas as $campo => [$convertir, $mensaje]) {
            if (!isset($query[$campo]) || $query[$campo] === '') {
                continue;   // filtro no usado
            }
            $valor = $convertir($query[$campo]);
            if ($valor === null) {
                $errores[$campo] = $mensaje;
            } else {
                $filtros[$campo] = $valor;
            }
        }

        if (isset($filtros['precio_min'], $filtros['precio_max']) && $filtros['precio_min'] > $filtros['precio_max']) {
            $errores['precio_max'] = 'Debe ser mayor o igual que precio_min';
        }

        $filtros['orden'] = $query['orden'] ?? 'novedades';
        if (!is_string($filtros['orden']) || !array_key_exists($filtros['orden'], ProductoRepositorio::ORDENES)) {
            $errores['orden'] = 'Valores: ' . implode(', ', array_keys(ProductoRepositorio::ORDENES));
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        return $filtros;
    }

    // --- Conversores de los filtros: devuelven el valor limpio o null si no es válido ---

    private function texto(mixed $valor): ?string
    {
        return (is_string($valor) && mb_strlen(trim($valor)) <= 60) ? trim($valor) : null;
    }

    private function identificador(mixed $valor): ?int
    {
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $entero === false ? null : $entero;
    }

    private function talla(mixed $valor): ?float
    {
        $talla = filter_var($valor, FILTER_VALIDATE_FLOAT);
        $valida = $talla !== false && $talla >= 34 && $talla <= 50 && fmod($talla * 2, 1.0) === 0.0;
        return $valida ? $talla : null;
    }

    private function precio(mixed $valor): ?float
    {
        $precio = filter_var($valor, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
        return $precio === false ? null : $precio;
    }

    private function booleano(mixed $valor): ?bool
    {
        // is_scalar: ?disponible[]=1 (un array) no es un booleano válido
        return is_scalar($valor) ? filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
    }
}
