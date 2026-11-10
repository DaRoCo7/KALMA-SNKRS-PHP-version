<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Nucleo\Peticion;

/**
 * Sesión del usuario con las sesiones nativas de PHP.
 *
 * - Cookie HttpOnly (JavaScript no puede leerla) y SameSite=Lax (no viaja en
 *   peticiones POST desde otras webs). Con HTTPS también lleva Secure.
 * - Al iniciar sesión se cambia el id de sesión (evita la fijación de sesión).
 * - Caduca tras 30 minutos sin actividad.
 * - Guarda el token CSRF que deben enviar las peticiones que modifican datos.
 */
final class Sesion
{
    private const NOMBRE_COOKIE = 'KALMA_SESION';
    private const INACTIVIDAD_MAXIMA = 1800;   // 30 minutos

    public static function iniciar(Peticion $peticion): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Detrás del proxy, la petición original llega como HTTPS en esta cabecera
        $https = ($peticion->cabecera('x-forwarded-proto') ?? '') === 'https';

        ini_set('session.use_strict_mode', '1');   // no acepta ids de sesión inventados
        ini_set('session.use_only_cookies', '1');  // el id nunca viaja en la URL
        session_name(self::NOMBRE_COOKIE);
        session_set_cookie_params([
            'lifetime' => 0,          // cookie de sesión: se borra al cerrar el navegador
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $ahora = time();
        if (isset($_SESSION['ultima_actividad']) && $ahora - $_SESSION['ultima_actividad'] > self::INACTIVIDAD_MAXIMA) {
            self::cerrar();
            session_start();
        }
        $_SESSION['ultima_actividad'] = $ahora;
    }

    /** @return array{id: int, nombre: string, apellidos: string, email: string, rol: string}|null */
    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    /** Tras un login o un registro correctos */
    public static function iniciarComo(array $usuario): void
    {
        session_regenerate_id(true);                       // id nuevo: el anterior deja de valer
        $_SESSION['usuario'] = self::datosPublicos($usuario);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));     // token CSRF nuevo para la sesión nueva
    }

    /** Mantiene al día el rol y el nombre si los cambia un administrador */
    public static function actualizarUsuario(array $usuario): void
    {
        $_SESSION['usuario'] = self::datosPublicos($usuario);
    }

    public static function cerrar(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $cookie['path'],
                'secure'   => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'],
            ]);
            session_destroy();
        }
    }

    public static function tokenCsrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /** hash_equals compara en tiempo constante: no da pistas sobre el token correcto */
    public static function tokenCsrfValido(string $enviado): bool
    {
        $esperado = $_SESSION['csrf'] ?? null;
        return is_string($esperado) && $enviado !== '' && hash_equals($esperado, $enviado);
    }

    /** Lo único que se guarda del usuario en la sesión (nunca el hash de la contraseña) */
    private static function datosPublicos(array $usuario): array
    {
        return [
            'id'        => (int) $usuario['id'],
            'nombre'    => $usuario['nombre'],
            'apellidos' => $usuario['apellidos'],
            'email'     => $usuario['email'],
            'rol'       => $usuario['rol'],
        ];
    }
}
