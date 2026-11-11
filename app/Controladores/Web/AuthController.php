<?php

declare(strict_types=1);

namespace App\Controladores\Web;

use App\Nucleo\HttpExcepcion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Seguridad\Sesion;
use App\Servicios\AuthServicio;

/**
 * Páginas de login, registro y cierre de sesión (formularios HTML).
 * Usan el mismo AuthServicio que la API; solo cambia la forma de responder:
 * si hay un error se vuelve a mostrar el formulario con los mensajes.
 *
 * Las dos páginas aceptan ?volver=/ruta para devolver al usuario a donde
 * estaba (por ejemplo, el catálogo al intentar comprar sin sesión).
 */
final class AuthController
{
    /** Adonde se va tras entrar si no se pidió otra cosa (o lo pedido no es fiable) */
    private const DESTINO_POR_DEFECTO = '/catalogo';

    public function __construct(private readonly AuthServicio $auth = new AuthServicio())
    {
    }

    /** GET /login */
    public function formularioLogin(Peticion $peticion): Respuesta
    {
        $volver = self::destinoSeguro($peticion->query['volver'] ?? null);
        if (Sesion::usuario() !== null) {
            return Respuesta::redireccion($volver ?? self::DESTINO_POR_DEFECTO);
        }
        return Respuesta::vista('auth/login', ['email' => '', 'error' => null, 'errores' => [], 'volver' => $volver]);
    }

    /** POST /login */
    public function login(Peticion $peticion): Respuesta
    {
        $datos = $peticion->entrada();
        $volver = self::destinoSeguro($datos['volver'] ?? null);

        try {
            $usuario = $this->auth->iniciarSesion($datos['email'] ?? null, $datos['password'] ?? null, $peticion->ip);
        } catch (HttpExcepcion $error) {
            return Respuesta::vista('auth/login', [
                'email'   => is_string($datos['email'] ?? null) ? $datos['email'] : '',
                'error'   => $error->getMessage(),
                'errores' => $error->detalles['errores'] ?? [],
                'volver'  => $volver,
            ], $error->estado);
        }

        Sesion::iniciarComo($usuario);
        return Respuesta::redireccion($volver ?? self::DESTINO_POR_DEFECTO);
    }

    /** GET /registro */
    public function formularioRegistro(Peticion $peticion): Respuesta
    {
        $volver = self::destinoSeguro($peticion->query['volver'] ?? null);
        if (Sesion::usuario() !== null) {
            return Respuesta::redireccion($volver ?? self::DESTINO_POR_DEFECTO);
        }
        return Respuesta::vista('auth/registro', ['valores' => [], 'errores' => [], 'error' => null, 'volver' => $volver]);
    }

    /** POST /registro */
    public function registro(Peticion $peticion): Respuesta
    {
        $datos = $peticion->entrada();
        $volver = self::destinoSeguro($datos['volver'] ?? null);

        try {
            $usuario = $this->auth->registrar($datos, $peticion->ip);
        } catch (HttpExcepcion $error) {
            unset($datos['password']);   // la contraseña nunca se devuelve al formulario
            $errores = $error->detalles['errores'] ?? [];
            // El email repetido llega como 409 sin detalle por campo: se pega al email
            // para que el aviso salga justo donde hay que corregir
            if ($error->estado === 409) {
                $errores['email'] ??= $error->getMessage();
            }
            // «valores» y no «datos»: Vista::renderizar ya tiene un parámetro $datos
            // y extract() no lo pisa, así que la vista nunca recibiría lo escrito
            return Respuesta::vista('auth/registro', [
                'valores' => array_filter($datos, 'is_string'),
                'errores' => $errores,
                'error'   => $error->getMessage(),
                'volver'  => $volver,
            ], $error->estado);
        }

        Sesion::iniciarComo($usuario);
        return Respuesta::redireccion($volver ?? self::DESTINO_POR_DEFECTO);
    }

    /** POST /logout */
    public function logout(Peticion $peticion): Respuesta
    {
        Sesion::cerrar();
        return Respuesta::redireccion('/catalogo');
    }

    /**
     * Devuelve la ruta de vuelta solo si es interna; si no, null.
     * Sin este filtro, un enlace como /login?volver=https://otra.web serviría para
     * mandar al usuario recién identificado a una copia falsa de la tienda
     * (redirección abierta). Por eso se exige que empiece por una sola «/»:
     * «//otra.web» y «/\otra.web» los navegadores los tratan como otro dominio,
     * y los caracteres de control se descartan porque el navegador quita los
     * tabuladores y saltos de línea de la URL y podría acabar en «//».
     */
    private static function destinoSeguro(mixed $valor): ?string
    {
        if (!is_string($valor) || $valor === '' || strlen($valor) > 500) {
            return null;
        }
        if ($valor[0] !== '/' || str_starts_with($valor, '//') || str_contains($valor, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $valor)) {
            return null;
        }
        // Volver al propio formulario tras entrar dejaría al usuario donde ya no le hace falta estar
        $ruta = '/' . trim((string) parse_url($valor, PHP_URL_PATH), '/');
        if (in_array($ruta, ['/login', '/registro', '/logout'], true)) {
            return null;
        }
        return $valor;
    }
}
