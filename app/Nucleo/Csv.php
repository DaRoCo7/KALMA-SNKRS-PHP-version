<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Archivos CSV para abrir en Excel en español (exportación de pedidos e
 * informes de stock). Las reglas viven aquí para que los dos archivos se
 * abran igual y ninguno se quede sin la protección contra fórmulas:
 *   - UTF-8 con BOM: sin él, Excel abre el CSV como ANSI y rompe las tildes y las eñes.
 *   - Separador «;» y coma decimal (119,99): con punto, Excel en español
 *     leería 12.05 como una fecha (12 de mayo).
 *   - Las celdas de texto que empiezan por = + - @ (o tabulador / retorno)
 *     llevan una comilla simple delante: Excel las trataría como fórmula y un
 *     email "=HYPERLINK(...)@x.com" se ejecutaría al abrir el archivo
 *     (inyección de fórmulas CSV).
 */
final class Csv
{
    public const TIPO = 'text/csv; charset=utf-8';

    private const INICIO_FORMULA = ['=', '+', '-', '@', "\t", "\r"];
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    /**
     * Contenido completo del archivo. Las celdas ya deben venir convertidas a
     * texto con texto(), decimal() o talla(): aquí no se sabe qué es cada columna.
     *
     * fputcsv escribe sobre un archivo temporal en memoria (php://temp): se
     * encarga de poner comillas y de escapar las que ya trae el texto.
     *
     * @param list<string> $columnas
     * @param iterable<list<string>> $filas
     */
    public static function generar(array $columnas, iterable $filas): string
    {
        $archivo = fopen('php://temp', 'r+');
        fwrite($archivo, self::BOM_UTF8);
        self::fila($archivo, $columnas);
        foreach ($filas as $celdas) {
            self::fila($archivo, $celdas);
        }

        rewind($archivo);
        $contenido = (string) stream_get_contents($archivo);
        fclose($archivo);
        return $contenido;
    }

    /** Celda de texto protegida: una comilla simple delante deja la fórmula como texto */
    public static function texto(?string $valor): string
    {
        $valor ??= '';
        return $valor !== '' && in_array($valor[0], self::INICIO_FORMULA, true) ? "'" . $valor : $valor;
    }

    /** 119.99 -> "119,99" · 12 -> "12,00" */
    public static function decimal(float $numero): string
    {
        return number_format($numero, 2, ',', '');
    }

    /** 42 -> "42" · 42.5 -> "42,5" (solo hay tallas enteras o medias) */
    public static function talla(float $talla): string
    {
        return number_format($talla, floor($talla) === $talla ? 0 : 1, ',', '');
    }

    /** @param resource $archivo */
    private static function fila($archivo, array $celdas): void
    {
        // escape '' = formato estándar RFC 4180 (las comillas se duplican, sin barra invertida)
        fputcsv($archivo, $celdas, ';', '"', '', "\r\n");
    }
}
