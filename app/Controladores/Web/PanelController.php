<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Permisos;
use App\Seguridad\Sesion;
use App\Servicios\EstadosPedido;

/**
 * Área interna (/panel) para administración y almacén.
 *
 * El servidor solo pinta la estructura: la barra lateral con las secciones que
 * el rol puede abrir y los datos del usuario. Cada sección es un módulo de
 * public/js/panel/<id>.js que pide sus datos a /api/gestion/... La pantalla
 * oculta lo que el rol no puede hacer, pero quien protege es la API.
 */
final class PanelController
{
    /**
     * Secciones del panel, en el orden de la barra lateral. Para añadir una:
     * una entrada aquí y el módulo public/js/panel/<id>.js. El permiso es el
     * mismo que exige la API de esa sección: sin él la sección ni se ofrece.
     */
    private const SECCIONES = [
        ['id' => 'resumen', 'nombre' => 'Resumen', 'grupo' => 'Operación', 'permiso' => 'informes.ver'],
        ['id' => 'pedidos', 'nombre' => 'Pedidos', 'grupo' => 'Operación', 'permiso' => 'pedidos.ver_todos'],
        ['id' => 'stock', 'nombre' => 'Stock', 'grupo' => 'Inventario', 'permiso' => 'stock.gestionar'],
        ['id' => 'albaranes', 'nombre' => 'Albaranes', 'grupo' => 'Inventario', 'permiso' => 'albaranes.ver'],
        ['id' => 'productos', 'nombre' => 'Productos', 'grupo' => 'Inventario', 'permiso' => 'productos.gestionar'],
        ['id' => 'informes', 'nombre' => 'Informes', 'grupo' => 'Análisis', 'permiso' => 'informes.ver'],
        // El almacén los genera y envía; administración (que también tiene este permiso) los revisa
        ['id' => 'informes-stock', 'nombre' => 'Informes de stock', 'grupo' => 'Análisis', 'permiso' => 'informes_stock.enviar'],
        ['id' => 'consultas', 'nombre' => 'Consultas', 'grupo' => 'Control', 'permiso' => 'consultas.gestionar'],
        // La auditoría no tiene pantalla: es un registro técnico que no le sirve a quien
        // lleva la tienda. Sigue guardándose y se consulta en GET /api/gestion/auditoria
    ];

    /** Cómo se llama cada rol en la pantalla */
    private const NOMBRES_ROL = [
        'admin'   => 'Administración',
        'almacen' => 'Almacén',
    ];

    /** GET /panel · la ruta ya exige sesión y el permiso panel.acceder */
    public function index(Peticion $peticion): Respuesta
    {
        $usuario = Sesion::usuario();
        $rol = $usuario['rol'];

        $secciones = array_values(array_filter(
            self::SECCIONES,
            static fn (array $s): bool => Permisos::tiene($rol, $s['permiso'])
        ));
        $permisos = array_values(array_filter(
            array_keys(Permisos::DESCRIPCIONES),
            static fn (string $p): bool => Permisos::tiene($rol, $p)
        ));

        return Respuesta::vista('panel/index', [
            'titulo'       => 'Panel',
            'usuario'      => $usuario,
            'nombreRol'    => self::NOMBRES_ROL[$rol] ?? $rol,
            'secciones'    => $secciones,
            'permisos'     => $permisos,
            'transiciones' => EstadosPedido::transicionesPara($rol),
        ]);
    }
}
