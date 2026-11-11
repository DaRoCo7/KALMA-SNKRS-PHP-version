<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Repositorios\PedidoRepositorio;

/**
 * Lectura de pedidos común al cliente y a la gestión interna: listado
 * paginado con sus filtros, búsqueda por código y detalle ya presentado.
 *
 * Existe aparte para que los dos lados validen los filtros con los mismos
 * mensajes y den el mismo 404 ante un código mal formado o inexistente. Quién
 * puede ver qué (solo lo propio o todo) lo decide quien llama: aquí solo llega
 * el cliente_id con el que acotar, o null.
 */
final class LecturaPedidos
{
    public const NO_ENCONTRADO = 'Pedido no encontrado';

    /** Formato del código visible de un pedido: SF-2026-000025 */
    private const FORMATO_CODIGO = '/^SF-\d{4}-\d{6,}$/';

    public function __construct(
        private readonly PedidoRepositorio $pedidos = new PedidoRepositorio(),
        private readonly PresentacionPedido $presentacion = new PresentacionPedido(),
    ) {
    }

    /** ¿Tiene el código el formato SF-AAAA-NNNNNN? Si no, no puede existir */
    public static function codigoValido(string $codigo): bool
    {
        return preg_match(self::FORMATO_CODIGO, $codigo) === 1;
    }

    /** Un código con otro formato no puede existir: 404 sin consultar la BD */
    public static function comprobarCodigo(string $codigo): void
    {
        if (!self::codigoValido($codigo)) {
            throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
        }
    }

    /**
     * @param int|null $clienteId si se indica, el pedido tiene que ser de ese cliente
     * @throws HttpExcepcion 404
     */
    public function buscar(string $codigo, ?int $clienteId): array
    {
        self::comprobarCodigo($codigo);
        return $this->pedidos->buscarPorCodigo($codigo, $clienteId) ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
    }

    /** Detalle de un pedido ya presentado (fila de la BD → JSON) */
    public function detalle(array $pedido, bool $vistaCliente): array
    {
        return $this->presentacion->detalle($pedido, $vistaCliente);
    }

    /**
     * Detalle releído de la BD tras una escritura (crear o cambiar de estado), para
     * devolver el pedido tal como ha quedado. El código ya se ha comprobado antes.
     */
    public function detallePorCodigo(string $codigo, bool $vistaCliente): array
    {
        return $this->presentacion->detalle($this->pedidos->buscarPorCodigo($codigo), $vistaCliente);
    }

    /**
     * Listado paginado. ?estado= en los dos lados; ?buscar= y ?personalizado=
     * solo en el área interna (en la del cliente se ignoran).
     *
     * @param array $acotar filtros que impone quien llama, p. ej. ['cliente_id' => 7]
     * @throws HttpExcepcion 400 si algún filtro no es válido
     */
    public function listado(array $query, bool $areaInterna, Paginacion $paginacion, array $acotar = []): array
    {
        $filtros = $acotar + $this->filtros($query, $areaInterna);
        $total = $this->pedidos->contar($filtros);
        $filas = $this->pedidos->listar($filtros, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map(fn (array $p): array => $this->presentacion->resumen($p) + [
                'lineas'   => (int) $p['num_lineas'],
                'unidades' => (int) $p['unidades'],
            ], $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /** Filtros de los listados: ?estado=pendiente (y ?buscar= y ?personalizado= en el área interna) */
    private function filtros(array $query, bool $areaInterna): array
    {
        $filtros = [];
        $errores = [];

        $estado = $query['estado'] ?? '';
        if ($estado !== '') {
            if (in_array($estado, EstadosPedido::ESTADOS, true)) {
                $filtros['estado'] = $estado;
            } else {
                $errores['estado'] = 'Valores: ' . implode(', ', EstadosPedido::ESTADOS);
            }
        }

        $buscar = $query['buscar'] ?? '';
        if ($areaInterna && $buscar !== '') {
            if (is_string($buscar) && mb_strlen(trim($buscar)) <= 60) {
                $filtros['buscar'] = trim($buscar);
            } else {
                $errores['buscar'] = 'Texto de hasta 60 caracteres';
            }
        }

        // ?personalizado=1 son los que el taller tiene que preparar a mano (bordado, caja...)
        $personalizado = $query['personalizado'] ?? '';
        if ($areaInterna && $personalizado !== '') {
            $valor = is_string($personalizado)
                ? filter_var($personalizado, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : null;
            if ($valor === null) {
                $errores['personalizado'] = 'Valores: 1 o 0';
            } else {
                $filtros['personalizado'] = $valor;
            }
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        return $filtros;
    }
}
