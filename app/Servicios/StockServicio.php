<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\StockRepositorio;

/**
 * Control de stock del área interna (almacén y administración): consultar las
 * tallas, ver su historial de movimientos y ajustar el stock a mano.
 *
 * Un ajuste corrige lo que dice el sistema para que coincida con lo que hay en
 * la estantería (un recuento, una caja dañada, una devolución). Nunca se escribe
 * el stock "a pelo": se suma o resta una cantidad con su motivo, y el movimiento
 * queda en movimientos_stock y en la auditoría.
 */
final class StockServicio
{
    /** Un ajuste mayor que esto no es una corrección: es una entrada de mercancía (albarán) */
    private const AJUSTE_MAXIMO = 500;
    private const MAX_ID = 2_147_483_647;   // máximo de un INTEGER de PostgreSQL
    private const NO_ENCONTRADA = 'Talla no encontrada';

    public function __construct(
        private readonly StockRepositorio $stock = new StockRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    /** Tallas con su stock: ?buscar (nombre, código o RFID), ?bajo_minimo=1 y ?producto_id */
    public function listar(array $query, Paginacion $paginacion): array
    {
        $filtros = $this->filtros($query);
        $total = $this->stock->contarTallas($filtros);
        $filas = $this->stock->listarTallas($filtros, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map(self::talla(...), $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /**
     * Suma o resta unidades a una talla. Cuerpo: {cantidad, motivo}.
     *
     * Se bloquea la talla (FOR UPDATE) antes de leer su stock: si a la vez entra
     * un pedido de esa talla, uno espera al otro y el stock nunca queda negativo
     * por una carrera entre los dos.
     *
     * @throws HttpExcepcion 400 datos no válidos · 404 no existe · 409 quedaría en negativo
     */
    public function ajustar(array $usuario, string $id, array $entrada, ?string $ip): array
    {
        $varianteId = self::id($id);
        [$cantidad, $motivo] = $this->validarAjuste($entrada);

        try {
            $resultante = BaseDatos::transaccion(function () use ($usuario, $varianteId, $cantidad, $motivo, $ip): int {
                $talla = $this->stock->bloquearVariantes([$varianteId])[$varianteId]
                    ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADA);

                // Se comprueba aquí para dar un mensaje claro con el stock que hay.
                // El CHECK (stock >= 0) de la tabla lo impediría igualmente.
                if ((int) $talla['stock'] + $cantidad < 0) {
                    throw HttpExcepcion::conflicto('El ajuste dejaría el stock en negativo', [
                        'stock_disponible' => (int) $talla['stock'],
                        'cantidad'         => $cantidad,
                    ]);
                }

                $resultante = $this->stock->mover($varianteId, $cantidad, 'ajuste', null, $usuario['id'], $motivo);
                $this->auditoria->registrar($usuario['id'], 'stock.ajustar', 'variante', $varianteId, 'exito', [
                    'variante_id'      => $varianteId,
                    'cantidad'         => $cantidad,
                    'stock_resultante' => $resultante,
                    'motivo'           => $motivo,
                ], $ip);
                return $resultante;
            });
        } catch (HttpExcepcion $error) {
            if ($error->estado === 409) {
                // Fuera de la transacción: el ROLLBACK habría borrado también este registro
                $this->auditoria->registrar($usuario['id'], 'stock.ajustar', 'variante', $varianteId, 'error', [
                    'variante_id'      => $varianteId,
                    'cantidad'         => $cantidad,
                    'stock_disponible' => $error->detalles['stock_disponible'] ?? null,
                    'motivo'           => $motivo,
                ], $ip);
            }
            throw $error;
        }

        // stock_resultante es lo que dejó ESTE ajuste, aunque otra petición haya
        // cambiado la talla justo después de confirmar la transacción
        return self::talla($this->stock->buscarTalla($varianteId)) + ['stock_resultante' => $resultante];
    }

    /**
     * Movimientos de una talla, del más reciente al más antiguo.
     *
     * @throws HttpExcepcion 404 si la talla no existe
     */
    public function movimientos(string $id, Paginacion $paginacion): array
    {
        $varianteId = self::id($id);
        $this->stock->buscarTalla($varianteId) ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADA);

        $total = $this->stock->contarMovimientos($varianteId);
        $filas = $this->stock->movimientos($varianteId, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map(static fn (array $m): array => [
                'id'               => (int) $m['id'],
                'tipo'             => $m['tipo'],
                'cantidad'         => (int) $m['cantidad'],
                'stock_resultante' => (int) $m['stock_resultante'],
                'pedido'           => $m['pedido_codigo'],
                'usuario'          => $m['usuario_id'] === null ? null : [
                    'id'     => (int) $m['usuario_id'],
                    'nombre' => trim("{$m['usuario_nombre']} {$m['usuario_apellidos']}"),
                    'rol'    => $m['usuario_rol'],
                ],
                'motivo'           => $m['motivo'],
                'fecha'            => self::fecha($m['fecha']),
            ], $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /**
     * cantidad: entero de -500 a 500 y distinto de 0 (un ajuste de 0 no cambia
     * nada y la tabla lo rechaza: CHECK cantidad <> 0). motivo: obligatorio,
     * porque un ajuste sin explicación no se puede revisar después.
     *
     * @return array{0: int, 1: string}
     */
    private function validarAjuste(array $entrada): array
    {
        $errores = [];
        $datos = [];
        try {
            $datos = (new Validador($entrada))
                ->entero('cantidad', -self::AJUSTE_MAXIMO, self::AJUSTE_MAXIMO)
                ->texto('motivo', 255)   // tamaño de movimientos_stock.motivo
                ->validar();
        } catch (HttpExcepcion $error) {
            $errores = $error->detalles['errores'] ?? [];
        }
        // Si cantidad no tiene error, ya es un entero válido y (int) es seguro
        if (!isset($errores['cantidad']) && (int) $entrada['cantidad'] === 0) {
            $errores['cantidad'] = 'Distinta de 0: positiva para sumar unidades, negativa para restarlas';
        }
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Hay datos no válidos', $errores);
        }
        return [$datos['cantidad'], $datos['motivo']];
    }

    /** @throws HttpExcepcion 400 si algún filtro no es válido */
    private function filtros(array $query): array
    {
        $filtros = [];
        $errores = [];

        $buscar = $query['buscar'] ?? '';
        if ($buscar !== '') {
            if (is_string($buscar) && mb_strlen(trim($buscar)) <= 60) {
                $filtros['buscar'] = trim($buscar);
            } else {
                $errores['buscar'] = 'Texto de hasta 60 caracteres';
            }
        }

        // Solo filtra con 1: con 0 (o sin el parámetro) salen todas las tallas
        $bajoMinimo = $query['bajo_minimo'] ?? '';
        if ($bajoMinimo !== '') {
            $valor = is_string($bajoMinimo) ? filter_var($bajoMinimo, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
            if ($valor === null) {
                $errores['bajo_minimo'] = 'Valores: 1 o 0';
            } elseif ($valor) {
                $filtros['bajo_minimo'] = true;
            }
        }

        $productoId = $query['producto_id'] ?? '';
        if ($productoId !== '') {
            $valor = is_string($productoId)
                ? filter_var($productoId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_ID]])
                : false;
            if ($valor === false) {
                $errores['producto_id'] = 'Identificador numérico del producto';
            } else {
                $filtros['producto_id'] = $valor;
            }
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        return $filtros;
    }

    /** El {id} de la URL: si no es un entero positivo, esa talla no puede existir (404 sin consultar la BD) */
    private static function id(string $id): int
    {
        $valor = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_ID]]);
        return $valor === false ? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADA) : $valor;
    }

    /** Talla tal como la devuelve la API (NUMERIC llega de PDO como texto: se pasa a número) */
    private static function talla(array $t): array
    {
        return [
            'variante_id'  => (int) $t['variante_id'],
            'producto'     => [
                'id'           => (int) $t['producto_id'],
                'nombre'       => $t['nombre'],
                'colorway'     => $t['colorway'],
                'codigo_marca' => $t['codigo_marca'],
                'slug'         => $t['slug'],
                'activo'       => $t['producto_activo'],
            ],
            'talla'        => (float) $t['talla'],
            'stock'        => (int) $t['stock'],
            'stock_minimo' => (int) $t['stock_minimo'],
            'bajo_minimo'  => $t['bajo_minimo'],
            'rfid_tienda'  => $t['rfid_tienda'],
            'activo'       => $t['activo'],
        ];
    }

    /** Fecha de PostgreSQL (2026-11-08 19:15:00+01) en ISO 8601 (2026-11-08T19:15:00+01:00) */
    private static function fecha(?string $valor): ?string
    {
        return $valor === null ? null : (new \DateTimeImmutable($valor))->format(DATE_ATOM);
    }
}
