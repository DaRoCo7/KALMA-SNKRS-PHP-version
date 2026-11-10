<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * Matriz de permisos: qué puede hacer cada rol.
 *
 * - admin:   todo.
 * - almacen: control de stock y de pedidos (ver todos y avanzar su estado).
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
        'pedidos.propios'        => 'Crear, ver y cancelar sus propios pedidos',
        'perfil.propio'          => 'Ver y editar sus propios datos',
        'pedidos.ver_todos'      => 'Ver los pedidos de todos los clientes',
        'pedidos.cambiar_estado' => 'Avanzar el estado de un pedido (confirmado, preparación, enviado...)',
        'stock.gestionar'        => 'Consultar y ajustar el stock de las tallas',
        'productos.gestionar'    => 'Dar de alta, editar y desactivar productos',
        'usuarios.gestionar'     => 'Listar usuarios y gestionar cuentas',
        'informes.ver'           => 'Ver el panel resumen y los informes',
        'auditoria.ver'          => 'Consultar el registro de auditoría',
        'datos.exportar'         => 'Exportar e importar pedidos en CSV o JSON',
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
