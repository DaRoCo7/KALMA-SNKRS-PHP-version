<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\ProductoRepositorio;
use App\Repositorios\StockRepositorio;

/**
 * Alta de un pedido nuevo: validar la entrada, bloquear las tallas, descontar
 * el stock sin vender dos veces la misma unidad y dejar el primer estado en el
 * historial y en la auditoría. O se hace entero o no se hace nada.
 *
 * Existe aparte de PedidoClienteServicio porque es la operación con más
 * dependencias (stock, catálogo de personalización, líneas) y la única que
 * escribe pedidos nuevos; el resto del área del cliente solo lee o cambia de
 * estado. La comprobación de cada línea está en LineasPedido.
 */
final class CreacionPedido
{
    private const DATOS_NO_VALIDOS = 'Hay datos no válidos';

    public function __construct(
        private readonly PedidoRepositorio $pedidos = new PedidoRepositorio(),
        private readonly StockRepositorio $stock = new StockRepositorio(),
        private readonly ProductoRepositorio $productos = new ProductoRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly LineasPedido $lineasPedido = new LineasPedido(),
        private readonly LecturaPedidos $lectura = new LecturaPedidos(),
    ) {
    }

    /**
     * @param array $cliente perfil de cliente del usuario (ya comprobado por quien llama)
     * @throws HttpExcepcion 400 datos no válidos · 409 alguna talla no tiene stock suficiente
     */
    public function crear(array $usuario, array $cliente, array $entrada, ?string $ip): array
    {
        // 1. Comprobar la forma de los datos sin tocar la BD, con todos los errores juntos
        $errores = [];
        $lineas = $this->lineasPedido->validar($entrada['lineas'] ?? null, $errores);
        $datos = [];
        try {
            $datos = (new Validador($entrada))
                ->texto('direccion_envio', 400, obligatorio: false)
                ->texto('notas', 500, obligatorio: false)
                ->validar();
        } catch (HttpExcepcion $error) {
            $errores += $error->detalles['errores'] ?? [];
        }
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, $errores);
        }

        $direccion = $datos['direccion_envio'] ?? $this->direccionDelPerfil($cliente)
            ?? throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, [
                'direccion_envio' => 'Obligatoria: tu perfil no tiene dirección',
            ]);
        $notas = $datos['notas'] ?? null;

        // Catálogo de opciones de personalización activas, indexado por id
        $opciones = array_column($this->productos->opcionesPersonalizacion(), null, 'id');

        // 2. Las escrituras van en una transacción: si algo falla no queda ni el
        //    pedido a medias ni stock descontado
        try {
            $nuevo = BaseDatos::transaccion(function () use ($usuario, $cliente, $lineas, $opciones, $direccion, $notas, $ip): array {
                // Bloquear las tallas ANTES de leer su stock (ver StockRepositorio)
                $variantes = $this->stock->bloquearVariantes(array_column($lineas, 'variante_id'));
                $preparadas = $this->lineasPedido->preparar($lineas, $variantes, $opciones);

                $personalizado = array_filter($preparadas, static fn (array $l): bool => $l['personalizacion'] !== []) !== [];
                $pedido = $this->pedidos->crear($cliente['id'], $direccion, $notas, $personalizado);

                foreach ($preparadas as $linea) {
                    $lineaId = $this->pedidos->agregarLinea($pedido['id'], $linea);
                    foreach ($linea['personalizacion'] as $opcion) {
                        $this->pedidos->agregarPersonalizacion($lineaId, $opcion['opcion_id'], $opcion['texto'], $opcion['recargo']);
                    }
                    $this->stock->mover(
                        $linea['variante_id'], -$linea['cantidad'], 'venta',
                        $pedido['id'], $usuario['id'], "Pedido {$pedido['codigo']}"
                    );
                }

                $total = $this->pedidos->actualizarTotal($pedido['id']);
                $this->pedidos->registrarEstado($pedido['id'], null, 'pendiente', $usuario['id'], 'Pedido creado por el cliente');
                $this->auditoria->registrar($usuario['id'], 'pedido.crear', 'pedido', $pedido['id'], 'exito', [
                    'codigo'   => $pedido['codigo'],
                    'lineas'   => count($preparadas),
                    'unidades' => array_sum(array_column($preparadas, 'cantidad')),
                    'total'    => $total,
                ], $ip);

                return $pedido;
            });
        } catch (HttpExcepcion $error) {
            if ($error->estado === 409) {
                // El intento fallido se audita FUERA de la transacción: dentro, el
                // ROLLBACK habría borrado también este registro
                $this->auditoria->registrar($usuario['id'], 'pedido.crear', 'pedido', null, 'error', [
                    'motivo'    => 'sin stock',
                    'sin_stock' => $error->detalles['sin_stock'] ?? [],
                ], $ip);
            }
            throw $error;
        }

        Log::info('Pedido creado', ['pedido_id' => $nuevo['id'], 'codigo' => $nuevo['codigo']]);
        return $this->lectura->detallePorCodigo($nuevo['codigo'], vistaCliente: true);
    }

    /**
     * "Calle Mayor 12, 2º A, 28013 Madrid (Madrid)", el mismo formato que los
     * pedidos de la semilla, o null si el perfil no tiene dirección.
     */
    private function direccionDelPerfil(array $cliente): ?string
    {
        if ($cliente['direccion'] === null) {
            return null;
        }
        $localidad = trim(($cliente['codigo_postal'] ?? '') . ' ' . ($cliente['ciudad'] ?? ''));
        $direccion = implode(', ', array_filter([$cliente['direccion'], $localidad]));
        return $cliente['provincia'] === null ? $direccion : "{$direccion} ({$cliente['provincia']})";
    }
}
