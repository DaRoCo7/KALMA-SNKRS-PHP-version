<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Repositorios\PedidoRepositorio;

/**
 * Convierte las filas de la BD en el JSON de un pedido (resumen de listado y
 * detalle completo).
 *
 * Existe aparte para que /cuenta y /panel pinten exactamente los mismos campos
 * con los mismos tipos: la única diferencia entre la vista del cliente y la
 * interna (quién hizo cada cambio del historial) se decide aquí y en ningún
 * otro sitio.
 */
final class PresentacionPedido
{
    public function __construct(private readonly PedidoRepositorio $pedidos = new PedidoRepositorio())
    {
    }

    /** Datos de un pedido en los listados */
    public function resumen(array $p): array
    {
        return [
            'id'             => (int) $p['id'],
            'codigo'         => $p['codigo'],
            'estado'         => $p['estado'],
            'personalizado'  => $p['personalizado'],
            'total'          => (float) $p['total'],
            'fecha_creacion' => self::fecha($p['fecha_creacion']),
            'cliente'        => [
                'id'     => (int) $p['cliente_id'],
                'nombre' => trim("{$p['cliente_nombre']} {$p['cliente_apellidos']}"),
                'email'  => $p['cliente_email'],
            ],
        ];
    }

    /**
     * Pedido completo: cabecera, líneas con su personalización e historial.
     * En la vista del cliente el historial dice qué rol hizo cada cambio, pero
     * no el nombre de la persona del almacén o de administración.
     */
    public function detalle(array $p, bool $vistaCliente): array
    {
        $lineas = array_map(static fn (array $l): array => [
            'variante_id'             => (int) $l['variante_id'],
            'producto'                => $l['nombre_producto'],
            'color'                   => $l['color'],
            'slug'                    => $l['slug'],
            'imagen'                  => '/' . $l['imagen'],
            'talla'                   => (float) $l['talla'],
            'cantidad'                => (int) $l['cantidad'],
            'precio_unitario'         => (float) $l['precio_unitario'],
            'recargo_personalizacion' => (float) $l['recargo_personalizacion'],
            'importe'                 => (float) $l['importe'],
            'personalizacion'         => json_decode($l['personalizacion'], true),
        ], $this->pedidos->lineas($p['id']));

        $historial = array_map(static fn (array $h): array => [
            'estado_anterior' => $h['estado_anterior'],
            'estado_nuevo'    => $h['estado_nuevo'],
            'fecha'           => self::fecha($h['fecha']),
            'comentario'      => $h['comentario'],
            'rol'             => $h['usuario_rol'],
        ] + ($vistaCliente ? [] : ['usuario' => $h['usuario_nombre']]), $this->pedidos->historial($p['id']));

        return $this->resumen($p) + [
            'direccion_envio'     => $p['direccion_envio'],
            'codigo_seguimiento'  => $p['codigo_seguimiento'],
            'notas'               => $p['notas'],
            'fecha_actualizacion' => self::fecha($p['fecha_actualizacion']),
            'se_puede_cancelar'   => EstadosPedido::sePuedeCancelar($p['estado']),
            'lineas'              => $lineas,
            'historial'           => $historial,
        ];
    }

    /** Fecha de PostgreSQL (2026-11-08 19:15:00+01) en ISO 8601 (2026-11-08T19:15:00+01:00) */
    public static function fecha(?string $valor): ?string
    {
        return $valor === null ? null : (new \DateTimeImmutable($valor))->format(DATE_ATOM);
    }
}
