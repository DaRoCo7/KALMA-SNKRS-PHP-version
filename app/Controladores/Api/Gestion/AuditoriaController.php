<?php

declare(strict_types=1);

namespace App\Controladores\Api\Gestion;

use App\Nucleo\Formato;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Repositorios\AuditoriaRepositorio;

/**
 * GET /api/gestion/auditoria?tipo=pedido.crear&entidad=pedido&resultado=exito&usuario_id=4&pagina=1&por_pagina=20
 * Permiso auditoria.ver (solo administración).
 *
 * Es una consulta de solo lectura sin reglas de negocio: igual que el listado
 * de usuarios, el controlador valida los filtros y llama al repositorio.
 */
final class AuditoriaController
{
    private const RESULTADOS = ['exito', 'error', 'denegado'];

    public function __construct(private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio())
    {
    }

    public function index(Peticion $peticion): Respuesta
    {
        // Hasta 100 eventos por página: al revisar la auditoría se leen muchos seguidos
        $paginacion = Paginacion::desdePeticion($peticion, 20, 100);
        $filtros = $this->filtros($peticion->query);

        $total = $this->auditoria->contar($filtros);
        $eventos = array_map(static fn (array $e): array => [
            'id'          => (int) $e['id'],
            'fecha'       => Formato::fecha($e['fecha']),
            'usuario'     => $e['usuario_id'] === null ? null : [
                'id'    => (int) $e['usuario_id'],
                'email' => $e['usuario_email'],
            ],
            'tipo_evento' => $e['tipo_evento'],
            'entidad'     => $e['entidad'],
            'entidad_id'  => $e['entidad_id'] === null ? null : (int) $e['entidad_id'],
            'resultado'   => $e['resultado'],
            // Como objeto y no como array: así un {} vacío sigue saliendo {} y no []
            'detalle'     => $e['detalle'] === null ? null : json_decode($e['detalle']),
            'ip'          => $e['ip'],
        ], $this->auditoria->listar($filtros, $paginacion->porPagina, $paginacion->offset()));

        return Respuesta::json(['datos' => $eventos, 'paginacion' => $paginacion->meta($total)]);
    }

    /**
     * Los tipos y las entidades los escribe el propio código (pedido.crear,
     * auth.login...): se exige ese formato y así un filtro raro es un 400 claro
     * en vez de un listado vacío.
     *
     * @throws HttpExcepcion 400
     */
    private function filtros(array $query): array
    {
        $reglas = [
            'tipo'       => ['/^[a-z_]+(\.[a-z_]+)*$/', 50, 'Tipo de evento, ej. pedido.crear'],
            'entidad'    => ['/^[a-z_]+$/', 50, 'Entidad, ej. pedido'],
            'resultado'  => ['/^(' . implode('|', self::RESULTADOS) . ')$/', 10, 'Valores: ' . implode(', ', self::RESULTADOS)],
            'usuario_id' => ['/^[1-9][0-9]{0,8}$/', 9, 'Id de usuario (entero positivo)'],
        ];

        $filtros = [];
        $errores = [];
        foreach ($reglas as $campo => [$patron, $maximo, $mensaje]) {
            $valor = $query[$campo] ?? '';
            if ($valor === '') {
                continue;
            }
            if (is_string($valor) && strlen($valor) <= $maximo && preg_match($patron, $valor)) {
                $filtros[$campo] = $campo === 'usuario_id' ? (int) $valor : $valor;
            } else {
                $errores[$campo] = $mensaje;
            }
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        return $filtros;
    }
}
