<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\HttpExcepcion;
use App\Repositorios\InformeRepositorio;

/**
 * Panel resumen e informes del área interna (permiso informes.ver).
 *
 * Los cálculos los hace la BD (InformeRepositorio). Aquí se validan los
 * parámetros, se elige el informe y se da formato a los números (NUMERIC de
 * PostgreSQL llega como texto y se devuelve como número).
 */
final class InformeServicio
{
    /** Modelos que se muestran en "modelos con menos stock" */
    private const MODELOS_MENOS_STOCK = 5;

    /** Informes que existen. El nombre de la URL tiene que ser uno de estos */
    public const INFORMES = ['ventas-por-modelo', 'ventas-por-mes', 'personalizaciones'];

    public function __construct(private readonly InformeRepositorio $informes = new InformeRepositorio())
    {
    }

    /** Indicadores del panel: estado de los pedidos, ventas y stock que hay que reponer */
    public function resumen(): array
    {
        // Los 6 estados siempre, con 0 si no hay pedidos: el panel no tiene que
        // adivinar qué estados faltan
        $porEstado = $this->informes->pedidosPorEstado();
        $pedidosPorEstado = [];
        foreach (EstadosPedido::ESTADOS as $estado) {
            $pedidosPorEstado[$estado] = $porEstado[$estado] ?? 0;
        }

        $ventas = $this->informes->ventas();

        return [
            'pedidos_por_estado'     => $pedidosPorEstado,
            'unidades_vendidas'      => $ventas['unidades'],
            'ingresos'               => $ventas['ingresos'],
            'pedidos_personalizados' => $ventas['personalizados'],
            'tallas_bajo_minimo'     => $this->informes->tallasBajoMinimo(),
            'modelos_menos_stock'    => array_map(static fn (array $m): array => [
                'id'                 => (int) $m['id'],
                'nombre'             => $m['nombre'],
                'colorway'           => $m['colorway'],
                'codigo_marca'       => $m['codigo_marca'],
                'slug'               => $m['slug'],
                'stock_total'        => (int) $m['stock_total'],
                'tallas'             => (int) $m['tallas'],
                'tallas_bajo_minimo' => (int) $m['tallas_bajo_minimo'],
            ], $this->informes->modelosMenosStock(self::MODELOS_MENOS_STOCK)),
        ];
    }

    /**
     * Ejecuta uno de los informes ya escritos. El match es una lista cerrada:
     * un nombre que no está en ella es un 404 y nunca llega a la BD.
     *
     * @throws HttpExcepcion 400 fechas no válidas · 404 informe desconocido
     */
    public function informe(string $nombre, array $query): array
    {
        if (!in_array($nombre, self::INFORMES, true)) {
            throw HttpExcepcion::noEncontrado('Informe no encontrado. Disponibles: ' . implode(', ', self::INFORMES));
        }
        ['desde' => $desde, 'hasta' => $hasta] = self::rangoFechas($query);

        $filas = match ($nombre) {
            'ventas-por-modelo' => array_map(static fn (array $f): array => [
                'producto_id'  => (int) $f['producto_id'],
                'nombre'       => $f['nombre'],
                'colorway'     => $f['colorway'],
                'codigo_marca' => $f['codigo_marca'],
            ] + self::cifras($f), $this->informes->ventasPorModelo($desde, $hasta)),

            'ventas-por-mes' => array_map(static fn (array $f): array => [
                'mes' => $f['mes'],
            ] + self::cifras($f), $this->informes->ventasPorMes($desde, $hasta)),

            'personalizaciones' => array_map(static fn (array $f): array => [
                'opcion_id'        => (int) $f['opcion_id'],
                'tipo'             => $f['tipo'],
                'nombre'           => $f['nombre'],
                'veces'            => (int) $f['veces'],
                'pares'            => (int) $f['pares'],
                'ingresos_recargo' => (float) $f['ingresos_recargo'],
            ], $this->informes->personalizaciones($desde, $hasta)),
        };

        return ['informe' => $nombre, 'desde' => $desde, 'hasta' => $hasta, 'filas' => $filas];
    }

    /**
     * Filtro de fechas ?desde=AAAA-MM-DD&hasta=AAAA-MM-DD, las dos opcionales e
     * inclusivas. Lo usan también las exportaciones.
     *
     * @return array{desde: ?string, hasta: ?string}
     * @throws HttpExcepcion 400 si una fecha no existe o desde es posterior a hasta
     */
    public static function rangoFechas(array $query): array
    {
        $fechas = [];
        $errores = [];
        foreach (['desde', 'hasta'] as $campo) {
            $valor = $query[$campo] ?? '';
            if ($valor === '') {
                $fechas[$campo] = null;
                continue;
            }
            // El formato y además que el día exista (2026-02-30 no pasa)
            if (is_string($valor) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes)
                && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
                $fechas[$campo] = $valor;
            } else {
                $errores[$campo] = 'Fecha con formato AAAA-MM-DD';
            }
        }

        // Con el formato AAAA-MM-DD el orden alfabético coincide con el cronológico
        if ($errores === [] && $fechas['desde'] !== null && $fechas['hasta'] !== null && $fechas['desde'] > $fechas['hasta']) {
            $errores['desde'] = 'No puede ser posterior a «hasta»';
        }
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Fechas no válidas', $errores);
        }

        return $fechas;
    }

    /** Columnas comunes de los informes de ventas */
    private static function cifras(array $f): array
    {
        return [
            'pedidos'  => (int) $f['pedidos'],
            'unidades' => (int) $f['unidades'],
            'ingresos' => (float) $f['ingresos'],
        ];
    }
}
