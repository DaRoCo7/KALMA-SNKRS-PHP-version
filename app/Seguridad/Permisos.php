<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * Matriz de permisos: qué puede hacer cada rol.
 *
 * - admin:   todo.
 * - almacen: control de stock y logística de los pedidos (ver todos y
 *            avanzarlos de confirmado a preparación, enviado y entregado).
 *            Confirmar un pago o cancelar desde el área interna es decisión
 *            de administración (pedidos.confirmar_cancelar).
 * - cliente: sus propios pedidos y su propio perfil. El catálogo es público y
 *            no necesita permiso.
 *
 * El cliente nunca borra ni sobrescribe recursos: cancelar un pedido es un
 * cambio de estado (POST /api/pedidos/{id}/cancelar) y editar su perfil solo
 * afecta a sus datos (PATCH /api/perfil). Que el pedido sea SUYO lo comprueba
 * el servicio de pedidos (protección IDOR), no esta matriz.
 *
 * Las rutas lo exigen con el middleware RequierePermiso, ej.:
 *   $router->get('/api/usuarios', [...], [Autenticado::class, new RequierePermiso('usuarios.gestionar')]);
 */
final class Permisos
{
    /** Permisos que existen y qué permiten (sirve de documentación) */
    public const DESCRIPCIONES = [
        'pedidos.propios'            => 'Crear, ver y cancelar sus propios pedidos',
        'perfil.propio'              => 'Ver y editar sus propios datos',
        'pedidos.ver_todos'          => 'Ver los pedidos de todos los clientes',
        'pedidos.cambiar_estado'     => 'Avanzar la logística de un pedido: preparación, enviado y entregado',
        'pedidos.confirmar_cancelar' => 'Confirmar el pago de un pedido o cancelarlo desde el área interna',
        'stock.gestionar'            => 'Consultar y ajustar el stock de las tallas',
        'productos.gestionar'        => 'Dar de alta, editar y desactivar productos, y cargar albaranes',
        'usuarios.gestionar'         => 'Listar usuarios y gestionar cuentas',
        'consultas.gestionar'        => 'Ver y responder las consultas del formulario de contacto',
        'informes.ver'               => 'Ver el panel resumen y los informes',
        'auditoria.ver'              => 'Consultar el registro de auditoría',
        'datos.exportar'             => 'Exportar los pedidos en CSV o JSON',
    ];

    /** '*' = todos los permisos */
    public const MATRIZ = [
        'admin'   => ['*'],
        'almacen' => ['stock.gestionar', 'pedidos.ver_todos', 'pedidos.cambiar_estado'],
        'cliente' => ['pedidos.propios', 'perfil.propio'],
    ];

    public static function tiene(string $rol, string $permiso): bool
    {
        $permisos = self::MATRIZ[$rol] ?? [];
        return in_array('*', $permisos, true) || in_array($permiso, $permisos, true);
    }
}
