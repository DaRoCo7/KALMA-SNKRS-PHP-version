<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\UsuarioRepositorio;

/**
 * Perfil del cliente: ver y editar sus propios datos.
 *
 * Solo se editan el nombre y los datos de contacto y envío. El email y la
 * contraseña NO se cambian aquí: el email es el usuario de acceso y cambiarlo
 * (igual que la contraseña) exigiría confirmar la identidad, así que queda
 * fuera de esta pantalla a propósito.
 *
 * Igual que los demás servicios, recibe el usuario de la sesión como array y no
 * sabe nada de HTTP ni de sesiones.
 */
final class PerfilServicio
{
    /** Campos que el cliente puede cambiar en PATCH /api/perfil */
    public const EDITABLES = ['nombre', 'apellidos', 'telefono', 'direccion', 'codigo_postal', 'ciudad', 'provincia'];

    public function __construct(
        private readonly UsuarioRepositorio $usuarios = new UsuarioRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    /** @throws HttpExcepcion 403 si la cuenta no tiene perfil de cliente (admin, almacén) */
    public function ver(array $usuario): array
    {
        return $this->buscar($usuario['id']);
    }

    /**
     * Edición parcial: solo cambia los campos que llegan. Los demás campos del
     * cuerpo (email, password, id...) se ignoran: el formulario del front puede
     * enviar el perfil entero tal como lo recibió.
     *
     * @throws HttpExcepcion 400 datos no válidos o ningún campo editable · 403 sin perfil de cliente
     */
    public function editar(array $usuario, array $entrada, ?string $ip): array
    {
        $actual = $this->buscar($usuario['id']);

        $enviados = array_intersect_key($entrada, array_flip(self::EDITABLES));
        if ($enviados === []) {
            throw HttpExcepcion::peticionIncorrecta('No se ha enviado ningún campo que se pueda editar', [
                'campos_editables' => implode(', ', self::EDITABLES),
            ]);
        }

        // Mismas reglas que el registro (AuthServicio). Nombre y apellidos son
        // obligatorios solo si se envían: así no se pueden dejar vacíos, pero un
        // PATCH que no los incluye tampoco falla por no traerlos.
        $datos = (new Validador($enviados))
            ->texto('nombre', 100, obligatorio: array_key_exists('nombre', $enviados))
            ->texto('apellidos', 150, obligatorio: array_key_exists('apellidos', $enviados))
            ->patron('telefono', '/^\+?[0-9 ]{9,20}$/', 'Teléfono no válido')
            ->texto('direccion', 200, obligatorio: false)
            ->patron('codigo_postal', '/^[0-9]{5}$/', 'Código postal de 5 cifras')
            ->texto('ciudad', 100, obligatorio: false)
            ->texto('provincia', 100, obligatorio: false)
            ->validar();
        // El Validador devuelve todos los campos (los no enviados como null):
        // nos quedamos solo con los que llegaron para no borrar los demás
        $datos = array_intersect_key($datos, $enviados);

        // Solo se escriben los que de verdad cambian: si no cambia nada, no hay
        // UPDATE ni registro de auditoría
        $cambiados = array_filter($datos, static fn (?string $valor, string $campo): bool => $valor !== $actual[$campo],
            ARRAY_FILTER_USE_BOTH);
        if ($cambiados === []) {
            return $actual;
        }

        // Dos tablas (usuarios y clientes) y la auditoría: todo o nada
        BaseDatos::transaccion(function () use ($usuario, $cambiados, $ip): void {
            $this->usuarios->actualizarNombre($usuario['id'], $cambiados);
            $this->usuarios->actualizarPerfilCliente($usuario['id'], $cambiados);
            // Solo QUÉ campos cambiaron, nunca sus valores: son datos personales
            $this->auditoria->registrar($usuario['id'], 'perfil.editar', 'usuario', $usuario['id'], 'exito', [
                'campos' => array_keys($cambiados),
            ], $ip);
        });

        return $this->buscar($usuario['id']);
    }

    /** @throws HttpExcepcion 403 */
    private function buscar(int $usuarioId): array
    {
        $perfil = $this->usuarios->perfilCompleto($usuarioId)
            ?? throw HttpExcepcion::prohibido('Solo las cuentas de cliente tienen perfil');
        $perfil['id'] = (int) $perfil['id'];
        return $perfil;
    }
}
