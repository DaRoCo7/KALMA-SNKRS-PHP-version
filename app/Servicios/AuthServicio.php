<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\UsuarioRepositorio;
use App\Seguridad\LimitadorTasa;

/**
 * Reglas de negocio del registro y del inicio de sesión.
 * No sabe nada de HTTP ni de sesiones: eso lo hacen los controladores, así que
 * la misma lógica sirve para la API y para las páginas HTML.
 */
final class AuthServicio
{
    /** Intentos de login por cuenta en la ventana (aunque el atacante cambie de IP) */
    private const INTENTOS_POR_CUENTA = 5;
    private const VENTANA_LOGIN = 900;   // 15 minutos

    /**
     * Hash bcrypt de una cadena aleatoria. Si el email no existe se comprueba la
     * contraseña contra este hash: la respuesta tarda lo mismo que con un email
     * real y no se puede averiguar qué emails están registrados midiendo tiempos.
     */
    private const HASH_FICTICIO = '$2y$10$/mzPgOJ39NqgX.ujfUow2OTLv6oC6s/q3BoJEuE.8iVvQ2XsByJce';

    public function __construct(
        private readonly UsuarioRepositorio $usuarios = new UsuarioRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly LimitadorTasa $limitador = new LimitadorTasa(),
    ) {
    }

    /**
     * Alta de un cliente nuevo (cuenta + perfil de cliente).
     *
     * @return array usuario creado (sin hash)
     * @throws HttpExcepcion 400 datos no válidos · 409 email o DNI ya registrados
     */
    public function registrar(array $entrada, ?string $ip): array
    {
        $datos = (new Validador($entrada))
            ->texto('nombre', 100)
            ->texto('apellidos', 150)
            ->email('email')
            ->contrasena('password')
            ->nif('nif')
            ->patron('telefono', '/^\+?[0-9 ]{9,20}$/', 'Teléfono no válido')
            ->texto('direccion', 200, obligatorio: false)
            ->patron('codigo_postal', '/^[0-9]{5}$/', 'Código postal de 5 cifras')
            ->texto('ciudad', 100, obligatorio: false)
            ->texto('provincia', 100, obligatorio: false)
            ->validar();

        if ($this->usuarios->emailRegistrado($datos['email'])) {
            throw HttpExcepcion::conflicto('Ya existe una cuenta con ese email');
        }

        try {
            // Dos tablas (usuarios + clientes) y la auditoría: todo o nada
            $id = BaseDatos::transaccion(function () use ($datos, $ip): int {
                $id = $this->usuarios->crearCliente($datos, password_hash($datos['password'], PASSWORD_DEFAULT));
                $this->auditoria->registrar($id, 'usuario.registrar', 'usuario', $id, 'exito', ['rol' => 'cliente'], $ip);
                return $id;
            });
        } catch (\PDOException $error) {
            // 23505 = clave única repetida: dos registros simultáneos con el mismo email o DNI
            if ($error->getCode() === '23505') {
                throw HttpExcepcion::conflicto(
                    str_contains($error->getMessage(), 'nif') ? 'Ya existe un cliente con ese DNI o NIE' : 'Ya existe una cuenta con ese email'
                );
            }
            throw $error;
        }

        Log::info('Cliente registrado', ['usuario_id' => $id]);
        return $this->usuarios->buscarPorId($id);
    }

    /**
     * Comprueba email y contraseña.
     *
     * @return array usuario autenticado (sin hash)
     * @throws HttpExcepcion 400 faltan datos · 401 credenciales incorrectas ·
     *                       403 cuenta desactivada · 429 demasiados intentos
     */
    public function iniciarSesion(mixed $email, mixed $password, ?string $ip): array
    {
        if (!is_string($email) || !is_string($password) || trim($email) === '' || $password === '') {
            throw HttpExcepcion::peticionIncorrecta('Email y contraseña son obligatorios', [
                'email' => 'Obligatorio', 'password' => 'Obligatorio',
            ]);
        }
        $email = mb_strtolower(trim($email));

        // Límite por cuenta: frena a quien prueba contraseñas contra un mismo email
        try {
            $this->limitador->consumir('login:email', $email, self::INTENTOS_POR_CUENTA, self::VENTANA_LOGIN);
        } catch (HttpExcepcion $limite) {
            $this->auditoria->registrar(null, 'auth.login', 'usuario', null, 'denegado', ['motivo' => 'límite de intentos'], $ip);
            throw $limite;
        }

        $usuario = $this->usuarios->buscarPorEmail($email);
        $correcta = password_verify($password, $usuario['password_hash'] ?? self::HASH_FICTICIO);

        if ($usuario === null || !$correcta) {
            // Mismo mensaje exista o no el email: no se revela qué cuentas hay
            $this->auditoria->registrar($usuario['id'] ?? null, 'auth.login', 'usuario', $usuario['id'] ?? null,
                'denegado', ['motivo' => 'credenciales incorrectas'], $ip);
            throw HttpExcepcion::noAutenticado('Email o contraseña incorrectos');
        }

        if (!$usuario['activo']) {
            $this->auditoria->registrar($usuario['id'], 'auth.login', 'usuario', $usuario['id'],
                'denegado', ['motivo' => 'cuenta desactivada'], $ip);
            throw HttpExcepcion::prohibido('La cuenta está desactivada');
        }

        if (password_needs_rehash($usuario['password_hash'], PASSWORD_DEFAULT)) {
            $this->usuarios->actualizarHash($usuario['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->limitador->reiniciar('login:email', $email);
        $this->usuarios->registrarAcceso($usuario['id']);
        $this->auditoria->registrar($usuario['id'], 'auth.login', 'usuario', $usuario['id'], 'exito', [], $ip);

        return $this->usuarios->buscarPorId($usuario['id']);
    }
}
