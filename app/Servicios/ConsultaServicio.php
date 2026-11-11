<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Formato;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\ConsultaRepositorio;

/**
 * Consultas del formulario de contacto: cualquier visitante (sin cuenta) las
 * envía y administración las gestiona (pendiente, respondida o cerrada).
 */
final class ConsultaServicio
{
    public const ESTADOS = ['pendiente', 'respondida', 'cerrada'];

    private const NO_ENCONTRADA = 'Consulta no encontrada';

    public function __construct(
        private readonly ConsultaRepositorio $consultas = new ConsultaRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    /**
     * Guarda una consulta nueva (estado pendiente). Las longitudes máximas son
     * las mismas que las columnas de la tabla: lo que pasa la validación cabe
     * siempre en la BD.
     *
     * @throws HttpExcepcion 400 datos no válidos
     */
    public function crear(array $entrada, ?string $ip): array
    {
        $datos = (new Validador($entrada))
            ->texto('nombre', 100)
            ->email('email')
            ->texto('asunto', 150)
            // Mínimo 10 caracteres: un mensaje más corto no explica nada. La "u"
            // cuenta caracteres y no bytes (la ñ cuenta 1) y la "s" admite saltos de línea
            ->patron('mensaje', '/^.{10,2000}$/su', 'Entre 10 y 2000 caracteres', obligatorio: true)
            ->validar();

        $consulta = $this->consultas->crear($datos['nombre'], $datos['email'], $datos['asunto'], $datos['mensaje']);

        // Sin usuario (el formulario es público) y sin el contenido: el mensaje
        // y el email son datos personales y la auditoría no los necesita
        $this->auditoria->registrar(null, 'consulta.crear', 'consulta', (int) $consulta['id'], 'exito', [], $ip);
        Log::info('Consulta de contacto recibida', ['consulta_id' => (int) $consulta['id']]);

        return self::formatear($consulta);
    }

    /** Listado del área interna: ?estado=pendiente&pagina=1 */
    public function listar(array $query, Paginacion $paginacion): array
    {
        $estado = ($query['estado'] ?? '') === '' ? null : $query['estado'];   // ?estado= vacío = sin filtro
        if ($estado !== null &&!in_array($estado, self::ESTADOS, true)) {
            throw HttpExcepcion::peticionIncorrecta('Filtro no válido', ['estado' => 'Valores: ' . implode(', ', self::ESTADOS)]);
        }

        $total = $this->consultas->contar($estado);
        $filas = $this->consultas->listar($estado, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map(self::formatear(...), $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /**
     * Marca una consulta como pendiente, respondida o cerrada. Entre estos tres
     * estados no hay un orden obligatorio: una consulta cerrada puede reabrirse
     * si el cliente vuelve a escribir.
     *
     * @throws HttpExcepcion 400 estado no válido · 404 no existe
     */
    public function cambiarEstado(array $usuario, string $id, array $entrada, ?string $ip): array
    {
        $idConsulta = Validador::idRuta($id, self::NO_ENCONTRADA);
        $datos = (new Validador($entrada))->opcion('estado', self::ESTADOS)->validar();

        $consulta = BaseDatos::transaccion(function () use ($idConsulta, $datos, $usuario, $ip): array {
            $consulta = $this->consultas->buscarPorId($idConsulta, bloquear: true)
                ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADA);

            // Si ya está en ese estado no se escribe nada (ni se audita un cambio que no ha habido)
            if ($consulta['estado'] !== $datos['estado']) {
                $this->consultas->cambiarEstado($idConsulta, $datos['estado']);
                $this->auditoria->registrar($usuario['id'], 'consulta.cambiar_estado', 'consulta', $idConsulta, 'exito', [
                    'de' => $consulta['estado'], 'a' => $datos['estado'],
                ], $ip);
                $consulta['estado'] = $datos['estado'];
            }
            return $consulta;
        });

        return self::formatear($consulta);
    }

    private static function formatear(array $c): array
    {
        return [
            'id'             => (int) $c['id'],
            'nombre'         => $c['nombre'],
            'email'          => $c['email'],
            'asunto'         => $c['asunto'],
            'mensaje'        => $c['mensaje'],
            'estado'         => $c['estado'],
            'fecha_consulta' => Formato::fecha($c['fecha_consulta']),
        ];
    }
}
