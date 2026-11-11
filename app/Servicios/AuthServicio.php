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
    /**
     * Límite de login por cuenta. Antes se gastaba un intento de la cuenta en
     * cada petición, viniera de donde viniera: quien supiera un email podía
     * escribir 5 contraseñas malas y dejar a su dueño 15 minutos sin entrar,
     * aunque luego escribiera la buena. Ahora hay dos contadores:
     *
     *   1. Cuenta + IP (bloqueo duro): 5 intentos cada 15 minutos desde una
     *      misma IP contra un mismo email, y a partir del sexto un 429 aunque
     *      la contraseña sea la buena. Se consume ANTES de comprobarla y con
     *      una sola sentencia atómica, así que ni lanzando 100 peticiones a la
     *      vez se cuela una más. El login correcto lo pone a cero. Frena la
     *      fuerza bruta desde una IP igual que antes, pero el bloqueo solo le
     *      cae a esa IP: el dueño entra desde la suya.
     *   2. Fallos de la cuenta desde cualquier IP (solo cuentan los fallos):
     *      si llegan a 10, la cuenta está bajo un ataque repartido entre IP y
     *      cada IP pasa a tener un único intento en la ventana. El atacante
     *      necesita una IP nueva por cada contraseña; el dueño, que no ha
     *      fallado desde la suya, sigue entrando a la primera.
     *
     * Por encima siguen el límite por IP de PHP (LIMITE_LOGIN_IP, 20 cada 15
     * minutos para todas las cuentas) y el de Nginx. Se descartó bloquear por
     * cuenta sin mirar la IP (es lo que permitía dejar fuera al dueño) y dejar
     * pasar siempre la contraseña buena (la diferencia entre «429» y «entra»
     * diría al atacante cuál es, sin límite de intentos).
     */
    private const INTENTOS_POR_CUENTA_E_IP = 5;
    private const FALLOS_CUENTA_BAJO_ATAQUE = 10;
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
     * @throws HttpExcepcion 400 datos no válidos · 409 email ya registrado
     */
    public function registrar(array $entrada, ?string $ip): array
    {
        $datos = (new Validador($entrada))
            ->texto('nombre', 100)
            ->texto('apellidos', 150)
            ->email('email')
            ->contrasena('password')
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
            // 23505 = clave única repetida: dos registros simultáneos con el mismo email
            if ($error->getCode() === '23505') {
                throw HttpExcepcion::conflicto('Ya existe una cuenta con ese email');
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

        $this->comprobarLimite($email, $ip);

        $usuario = $this->usuarios->buscarPorEmail($email);
        $correcta = password_verify($password, $usuario['password_hash'] ?? self::HASH_FICTICIO);

        if ($usuario === null || !$correcta) {
            // Solo los fallos cuentan para la cuenta (también si el email no existe:
            // así la respuesta no cambia según haya cuenta o no)
            $this->limitador->anotar('login:fallos_cuenta', $email, self::VENTANA_LOGIN);
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

        // Solo el contador de esta IP: los fallos de la cuenta desde otras IP
        // siguen contando, porque entrar el dueño no para un ataque repartido
        $this->limitador->reiniciar('login:cuenta_ip', self::claveCuentaIp($email, $ip));
        $this->usuarios->registrarAcceso($usuario['id']);
        $this->auditoria->registrar($usuario['id'], 'auth.login', 'usuario', $usuario['id'], 'exito', [], $ip);

        return $this->usuarios->buscarPorId($usuario['id']);
    }

    /**
     * Las dos reglas del límite por cuenta (ver la constante INTENTOS_POR_CUENTA_E_IP).
     * Cada 429 queda en la auditoría con su motivo, sin el email.
     *
     * @throws HttpExcepcion 429 con Retry-After
     */
    private function comprobarLimite(string $email, ?string $ip): void
    {
        $denegar = function (string $motivo, ?HttpExcepcion $limite = null) use ($ip): never {
            $this->auditoria->registrar(null, 'auth.login', 'usuario', null, 'denegado', ['motivo' => $motivo], $ip);
            throw $limite ?? HttpExcepcion::demasiadasPeticiones(self::VENTANA_LOGIN);
        };

        try {
            $intentosIp = $this->limitador->consumir('login:cuenta_ip', self::claveCuentaIp($email, $ip),
                self::INTENTOS_POR_CUENTA_E_IP, self::VENTANA_LOGIN);
        } catch (HttpExcepcion $limite) {
            $denegar('límite de intentos', $limite);
        }

        // Bajo ataque, una IP que ya lo ha intentado no tiene más intentos en la ventana
        if ($intentosIp > 1
            && $this->limitador->intentos('login:fallos_cuenta', $email, self::VENTANA_LOGIN) >= self::FALLOS_CUENTA_BAJO_ATAQUE) {
            Log::aviso('Login frenado: cuenta con fallos desde varias IP', ['intentos_ip' => $intentosIp]);
            $denegar('límite de intentos (cuenta bajo ataque)');
        }
    }

    /** Email + IP en una sola clave (el limitador guarda solo su hash) */
    private static function claveCuentaIp(string $email, ?string $ip): string
    {
        return $email . '|' . ($ip ?? 'sin-ip');
    }
}
