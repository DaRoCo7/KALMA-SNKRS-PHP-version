<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\UsuarioRepositorio;

/**
 * Pedidos propios del cliente (/api/pedidos): crear, listar, ver y cancelar.
 *
 * Existe para concentrar las dos reglas que solo tiene este lado: que la cuenta
 * sea de cliente y que nunca se vea ni se toque un pedido ajeno (IDOR), con
 * rastro en la auditoría cuando alguien lo intenta. El alta la hace
 * CreacionPedido y la cancelación EstadosPedido, la misma que usa administración.
 * Recibe el usuario de la sesión como array, pero no sabe nada de HTTP.
 */
final class PedidoClienteServicio
{
    public function __construct(
        private readonly UsuarioRepositorio $usuarios = new UsuarioRepositorio(),
        private readonly PedidoRepositorio $pedidos = new PedidoRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly LecturaPedidos $lectura = new LecturaPedidos(),
        private readonly EstadosPedido $estados = new EstadosPedido(),
        private readonly CreacionPedido $creacion = new CreacionPedido(),
    ) {
    }

    /**
     * Crea un pedido con varias líneas y descuenta el stock.
     *
     * @throws HttpExcepcion 400 datos no válidos · 403 la cuenta no es de cliente ·
     *                       409 alguna talla no tiene stock suficiente
     */
    public function crear(array $usuario, array $entrada, ?string $ip): array
    {
        return $this->creacion->crear($usuario, $this->perfilCliente($usuario), $entrada, $ip);
    }

    /** Pedidos del cliente que ha iniciado sesión (nunca los de otros) */
    public function listarPropios(array $usuario, array $query, Paginacion $paginacion): array
    {
        $cliente = $this->perfilCliente($usuario);
        return $this->lectura->listado($query, false, $paginacion, ['cliente_id' => $cliente['id']]);
    }

    /**
     * @param string|null $ip para la auditoría si el pedido es de otro cliente
     * @throws HttpExcepcion 404 si no existe o es de otro cliente
     */
    public function verPropio(array $usuario, string $codigo, ?string $ip = null): array
    {
        $cliente = $this->perfilCliente($usuario);
        try {
            $pedido = $this->lectura->buscar($codigo, $cliente['id']);
        } catch (HttpExcepcion $error) {
            $this->registrarAccesoAjeno($usuario, $codigo, 'pedido.ver', $ip);
            throw $error;
        }
        return $this->lectura->detalle($pedido, vistaCliente: true);
    }

    /**
     * El cliente cancela su pedido (solo desde pendiente o confirmado) y el
     * stock se repone.
     *
     * @throws HttpExcepcion 404 no existe o es de otro · 409 su estado ya no permite cancelar
     */
    public function cancelarPropio(array $usuario, string $codigo, array $entrada, ?string $ip): array
    {
        $cliente = $this->perfilCliente($usuario);
        $datos = (new Validador($entrada))->texto('motivo', 200, obligatorio: false)->validar();

        $comentario = 'Cancelado por el cliente' . (isset($datos['motivo']) ? ': ' . $datos['motivo'] : '');
        try {
            $this->estados->transicionar($codigo, $cliente['id'], 'cancelado', $usuario, $comentario, null, $ip);
        } catch (HttpExcepcion $error) {
            if ($error->estado === 404) {
                $this->registrarAccesoAjeno($usuario, $codigo, 'pedido.cancelar', $ip);
            }
            throw $error;
        }

        return $this->lectura->detallePorCodigo($codigo, vistaCliente: true);
    }

    /**
     * Protección IDOR con rastro.
     *
     * La clienta recibe el MISMO 404 si el código no existe que si es de otro
     * cliente: así no puede averiguar qué códigos existen probando. Pero si el
     * pedido SÍ existe, alguien está pidiendo pedidos ajenos (cambiando el código
     * de la URL) y administración debe poder verlo en la auditoría.
     */
    private function registrarAccesoAjeno(array $usuario, string $codigo, string $tipoEvento, ?string $ip): void
    {
        if (!LecturaPedidos::codigoValido($codigo)) {
            return;   // con otro formato no puede existir: ni se consulta la BD
        }
        $ajeno = $this->pedidos->buscarPorCodigo($codigo);
        if ($ajeno !== null) {
            $this->auditoria->registrar($usuario['id'], $tipoEvento, 'pedido', (int) $ajeno['id'], 'denegado', [
                'codigo' => $codigo,
                'motivo' => 'el pedido pertenece a otro cliente',
            ], $ip);
        }
    }

    /** @throws HttpExcepcion 403 si la cuenta no tiene perfil de cliente (admin, almacén) */
    private function perfilCliente(array $usuario): array
    {
        return $this->usuarios->perfilCliente($usuario['id'])
            ?? throw HttpExcepcion::prohibido('Solo las cuentas de cliente tienen pedidos propios');
    }
}
