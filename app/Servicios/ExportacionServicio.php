<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\Csv;
use App\Nucleo\Formato;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\ExportacionRepositorio;

/**
 * Exportación de los pedidos a un archivo CSV (para abrirlo en Excel) o JSON
 * (para otro programa). Permiso datos.exportar (solo administración).
 *
 * A diferencia de los informes, la exportación incluye también los pedidos
 * cancelados: es una copia de los datos, no un cálculo de ventas. Se pueden
 * filtrar con ?estado=.
 */
final class ExportacionServicio
{
    public const FORMATOS = ['csv', 'json'];

    /** Columnas del CSV, en orden: una fila por línea de pedido */
    private const COLUMNAS_CSV = [
        'codigo', 'fecha', 'estado', 'cliente_email', 'producto', 'color', 'talla', 'cantidad',
        'precio_unitario', 'recargo_personalizacion', 'importe', 'personalizacion', 'total_pedido',
    ];


    public function __construct(
        private readonly ExportacionRepositorio $exportacion = new ExportacionRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    /**
     * @return array{contenido: string, tipo: string, nombre_archivo: string}
     * @throws HttpExcepcion 400 formato, estado o fechas no válidos
     */
    public function pedidos(array $usuario, array $query, ?string $ip): array
    {
        $filtros = $this->filtros($query);
        $formato = $filtros['formato'];
        unset($filtros['formato']);

        $lineas = $this->exportacion->lineasDePedidos($filtros);
        $numPedidos = count(array_unique(array_column($lineas, 'pedido_id')));

        $contenido = $formato === 'csv' ? $this->csv($lineas) : $this->json($lineas);

        // Qué se exportó y cuánto, no los datos: quedan en el archivo, no en la auditoría
        $this->auditoria->registrar($usuario['id'], 'pedidos.exportar', 'pedido', null, 'exito', [
            'formato' => $formato,
            // (object): sin filtros se guarda {} y no [], igual que cuando los hay
            'filtros' => (object) array_filter($filtros, static fn (?string $valor): bool => $valor !== null),
            'pedidos' => $numPedidos,
            'filas'   => count($lineas),
        ], $ip);
        Log::info('Pedidos exportados', ['formato' => $formato, 'filas' => count($lineas)]);

        // Nombre fijo generado aquí (la petición no lo elige): kalma-pedidos-20261110.csv
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Ymd');

        return [
            'contenido'      => $contenido,
            'tipo'           => $formato === 'csv' ? Csv::TIPO : 'application/json; charset=utf-8',
            'nombre_archivo' => "kalma-pedidos-{$hoy}.{$formato}",
        ];
    }

    /**
     * CSV para Excel en español (BOM, «;», coma decimal y celdas protegidas
     * contra fórmulas: ver Csv). Una fila por línea de pedido.
     */
    private function csv(array $lineas): string
    {
        return Csv::generar(self::COLUMNAS_CSV, array_map(static fn (array $l): array => [
            Csv::texto($l['codigo']),
            Formato::fecha($l['fecha_creacion']),
            Csv::texto($l['estado']),
            Csv::texto($l['cliente_email']),
            Csv::texto($l['nombre_producto']),
            Csv::texto($l['color']),
            Csv::talla((float) $l['talla']),
            (string) (int) $l['cantidad'],
            Csv::decimal((float) $l['precio_unitario']),
            Csv::decimal((float) $l['recargo_personalizacion']),
            Csv::decimal((float) $l['importe']),
            Csv::texto(self::personalizacionEnTexto(json_decode($l['personalizacion'], true))),
            Csv::decimal((float) $l['total']),
        ], $lineas));
    }

    /** Lista de pedidos con sus líneas anidadas (agrupa las filas por pedido) */
    private function json(array $lineas): string
    {
        $pedidos = [];
        foreach ($lineas as $l) {
            $pedidos[$l['pedido_id']] ??= [
                'codigo'        => $l['codigo'],
                'fecha'         => Formato::fecha($l['fecha_creacion']),
                'estado'        => $l['estado'],
                'cliente_email' => $l['cliente_email'],
                'personalizado' => $l['personalizado'],
                'total'         => (float) $l['total'],
                'lineas'        => [],
            ];
            $pedidos[$l['pedido_id']]['lineas'][] = [
                'producto'                => $l['nombre_producto'],
                'color'                   => $l['color'],
                'talla'                   => (float) $l['talla'],
                'cantidad'                => (int) $l['cantidad'],
                'precio_unitario'         => (float) $l['precio_unitario'],
                'recargo_personalizacion' => (float) $l['recargo_personalizacion'],
                'importe'                 => (float) $l['importe'],
                'personalizacion'         => json_decode($l['personalizacion'], true),
            ];
        }

        return json_encode(
            array_values($pedidos),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /**
     * ?formato=csv|json (obligatorio), ?estado= y ?desde=&hasta= opcionales.
     *
     * @throws HttpExcepcion 400 con todos los errores juntos
     */
    private function filtros(array $query): array
    {
        $errores = [];
        $filtros = [];
        try {
            $filtros = (new Validador($query))
                ->opcion('formato', self::FORMATOS)
                ->patron('estado', '/^(' . implode('|', EstadosPedido::ESTADOS) . ')$/',
                    'Valores: ' . implode(', ', EstadosPedido::ESTADOS))
                ->validar();
        } catch (HttpExcepcion $error) {
            $errores = $error->detalles['errores'] ?? [];
        }
        try {
            $filtros += InformeServicio::rangoFechas($query);
        } catch (HttpExcepcion $error) {
            $errores += $error->detalles['errores'] ?? [];
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Parámetros de exportación no válidos', $errores);
        }
        return $filtros;
    }


    /** «Caja edición KALMA; Nombre bordado en el talón: LUCÍA» */
    private static function personalizacionEnTexto(array $opciones): string
    {
        return implode('; ', array_map(
            static fn (array $o): string => $o['texto'] === null ? $o['nombre'] : "{$o['nombre']}: {$o['texto']}",
            $opciones
        ));
    }
}
