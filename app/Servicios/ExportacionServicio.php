<?php

declare(strict_types=1);

namespace App\Servicios;

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

    /**
     * Excel trata como fórmula una celda que empieza por estos caracteres: un
     * email "=HYPERLINK(...)@x.com" ejecutaría la fórmula al abrir el archivo
     * (inyección de fórmulas CSV). Una comilla simple delante lo deja como texto.
     */
    private const INICIO_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /** UTF-8 con BOM: sin él, Excel abre el CSV como ANSI y rompe las tildes y las eñes */
    private const BOM_UTF8 = "\xEF\xBB\xBF";

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
            'tipo'           => $formato === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8',
            'nombre_archivo' => "kalma-pedidos-{$hoy}.{$formato}",
        ];
    }

    /**
     * CSV para Excel en español: separador punto y coma (la coma es el separador
     * decimal) y decimales con coma, 119,99. Con punto, Excel en español leería
     * 12.05 como una fecha (12 de mayo).
     *
     * fputcsv escribe sobre un archivo temporal en memoria (php://temp): se
     * encarga de poner comillas y de escapar las que ya trae el texto.
     */
    private function csv(array $lineas): string
    {
        $archivo = fopen('php://temp', 'r+');
        fwrite($archivo, self::BOM_UTF8);
        $this->filaCsv($archivo, self::COLUMNAS_CSV);

        foreach ($lineas as $l) {
            $this->filaCsv($archivo, [
                self::texto($l['codigo']),
                self::fecha($l['fecha_creacion']),
                self::texto($l['estado']),
                self::texto($l['cliente_email']),
                self::texto($l['nombre_producto']),
                self::texto($l['color']),
                self::talla((float) $l['talla']),
                (string) (int) $l['cantidad'],
                self::decimal((float) $l['precio_unitario']),
                self::decimal((float) $l['recargo_personalizacion']),
                self::decimal((float) $l['importe']),
                self::texto(self::personalizacionEnTexto(json_decode($l['personalizacion'], true))),
                self::decimal((float) $l['total']),
            ]);
        }

        rewind($archivo);
        $contenido = (string) stream_get_contents($archivo);
        fclose($archivo);
        return $contenido;
    }

    /** Lista de pedidos con sus líneas anidadas (agrupa las filas por pedido) */
    private function json(array $lineas): string
    {
        $pedidos = [];
        foreach ($lineas as $l) {
            $pedidos[$l['pedido_id']] ??= [
                'codigo'        => $l['codigo'],
                'fecha'         => self::fecha($l['fecha_creacion']),
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
                ->patron('estado', '/^(' . implode('|', PedidoServicio::ESTADOS) . ')$/',
                    'Valores: ' . implode(', ', PedidoServicio::ESTADOS))
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

    /** @param resource $archivo */
    private function filaCsv($archivo, array $celdas): void
    {
        // escape '' = formato estándar RFC 4180 (las comillas se duplican, sin barra invertida)
        fputcsv($archivo, $celdas, ';', '"', '', "\r\n");
    }

    /** Celda de texto protegida contra la inyección de fórmulas */
    private static function texto(?string $valor): string
    {
        $valor ??= '';
        return $valor !== '' && in_array($valor[0], self::INICIO_FORMULA, true) ? "'" . $valor : $valor;
    }

    /** 119.99 -> "119,99" · 12 -> "12,00" */
    private static function decimal(float $numero): string
    {
        return number_format($numero, 2, ',', '');
    }

    /** 42 -> "42" · 42.5 -> "42,5" (solo hay tallas enteras o medias) */
    private static function talla(float $talla): string
    {
        return number_format($talla, floor($talla) === $talla ? 0 : 1, ',', '');
    }

    /** «Caja edición KALMA; Nombre bordado en el talón: LUCÍA» */
    private static function personalizacionEnTexto(array $opciones): string
    {
        return implode('; ', array_map(
            static fn (array $o): string => $o['texto'] === null ? $o['nombre'] : "{$o['nombre']}: {$o['texto']}",
            $opciones
        ));
    }

    /** Fecha de PostgreSQL en ISO 8601 (2026-11-08T19:15:00+01:00) */
    private static function fecha(string $valor): string
    {
        return (new \DateTimeImmutable($valor))->format(DATE_ATOM);
    }
}
