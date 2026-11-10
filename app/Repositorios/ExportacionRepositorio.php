<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Datos para exportar los pedidos (CSV o JSON). Solo SQL de lectura: el
 * formato del archivo lo decide ExportacionServicio.
 */
final class ExportacionRepositorio
{
    /**
     * Una fila por LÍNEA de pedido, con los datos de su pedido y del cliente
     * repetidos en cada una (es lo que necesita un CSV). La personalización de
     * la línea llega como JSON, en el orden del catálogo de opciones.
     *
     * Las líneas guardan nombre, color, talla y precio del momento de la compra:
     * la exportación dice lo que se vendió aunque después cambie el catálogo.
     *
     * @param array{estado?: string, desde?: ?string, hasta?: ?string} $filtros
     *        desde y hasta (AAAA-MM-DD) inclusivas, en hora de España
     */
    public function lineasDePedidos(array $filtros): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            "SELECT p.id AS pedido_id, p.codigo, p.fecha_creacion, p.estado, p.personalizado, p.total,
                    u.email AS cliente_email,
                    l.nombre_producto, l.color, l.talla, l.cantidad, l.precio_unitario,
                    l.recargo_personalizacion, l.importe,
                    COALESCE((
                        SELECT json_agg(json_build_object(
                                   'tipo', o.tipo, 'nombre', o.nombre,
                                   'texto', lp.texto, 'recargo', lp.recargo_aplicado
                               ) ORDER BY o.id)
                        FROM linea_personalizacion lp
                        JOIN opciones_personalizacion o ON o.id = lp.opcion_id
                        WHERE lp.linea_id = l.id
                    ), '[]') AS personalizacion
             FROM pedidos p
             JOIN clientes c      ON c.id = p.cliente_id
             JOIN usuarios u      ON u.id = c.usuario_id
             JOIN lineas_pedido l ON l.pedido_id = p.id
             WHERE (CAST(:estado AS varchar) IS NULL OR p.estado = :estado_filtro)
               AND (CAST(:desde AS date) IS NULL OR p.fecha_creacion >= CAST(:desde_filtro AS date))
               AND (CAST(:hasta AS date) IS NULL OR p.fecha_creacion < CAST(:hasta_filtro AS date) + 1)
             ORDER BY p.fecha_creacion, p.id, l.id"
        );
        $consulta->execute([
            'estado' => $filtros['estado'] ?? null, 'estado_filtro' => $filtros['estado'] ?? null,
            'desde'  => $filtros['desde'] ?? null,  'desde_filtro'  => $filtros['desde'] ?? null,
            'hasta'  => $filtros['hasta'] ?? null,  'hasta_filtro'  => $filtros['hasta'] ?? null,
        ]);
        return $consulta->fetchAll();
    }
}
