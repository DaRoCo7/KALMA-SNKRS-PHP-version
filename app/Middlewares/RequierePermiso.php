<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Middleware;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Repositorios\AuditoriaRepositorio;
use App\Seguridad\Permisos;
use App\Seguridad\Sesion;

/**
 * Exige un permiso de la matriz (App\Seguridad\Permisos). Va siempre después
 * de Autenticado. Si el rol no lo tiene: 403 y el intento queda en auditoría.
 * En la API el 403 es JSON; en las páginas (/panel), la página de error HTML.
 */
final class RequierePermiso implements Middleware
{
    public function __construct(private readonly string $permiso)
    {
    }

    public function procesar(Peticion $peticion, callable $siguiente): Respuesta
    {
        $usuario = Sesion::usuario() ?? throw HttpExcepcion::noAutenticado();

        if (!Permisos::tiene($usuario['rol'], $this->permiso)) {
            (new AuditoriaRepositorio())->registrar(
                $usuario['id'], 'acceso.denegado', 'ruta', null, 'denegado',
                ['metodo' => $peticion->metodo, 'ruta' => $peticion->ruta, 'permiso' => $this->permiso],
                $peticion->ip
            );
            // En una página el 403 lo pinta ManejadorErrores como HTML (errores/error.php);
            // el mensaje habla de la página porque ahí no hay ninguna «operación» a la vista
            throw $peticion->esApi()
                ? HttpExcepcion::prohibido()
                : HttpExcepcion::prohibido('Tu cuenta no tiene acceso a esta página');
        }

        return $siguiente($peticion);
    }
}
