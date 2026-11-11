<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Enrutador: decide qué controlador atiende cada petición según el método
 * HTTP y la ruta. Las rutas se definen en config/rutas.php y en los módulos de
 * config/rutas/. No hay PUT: todas las ediciones son parciales y usan PATCH.
 *
 *   $router->get('/api/productos/{id}', [ProductoController::class, 'ver'], [Autenticado::class]);
 */
final class Router
{
    /** @var list<array{metodo: string, patron: string, accion: callable|array, middlewares: array}> */
    private array $rutas = [];

    /** @var list<Middleware|string> Middlewares que se ejecutan en todas las rutas */
    private array $globales = [];

    /** Añade un middleware común a todas las rutas (sesión, CSRF...) */
    public function usar(Middleware|string $middleware): void
    {
        $this->globales[] = $middleware;
    }

    public function get(string $ruta, callable|array $accion, array $middlewares = []): void
    {
        $this->agregar('GET', $ruta, $accion, $middlewares);
    }

    public function post(string $ruta, callable|array $accion, array $middlewares = []): void
    {
        $this->agregar('POST', $ruta, $accion, $middlewares);
    }

    public function patch(string $ruta, callable|array $accion, array $middlewares = []): void
    {
        $this->agregar('PATCH', $ruta, $accion, $middlewares);
    }

    public function delete(string $ruta, callable|array $accion, array $middlewares = []): void
    {
        $this->agregar('DELETE', $ruta, $accion, $middlewares);
    }

    /**
     * Convierte la ruta en una expresión regular:
     * '/api/pedidos/{id}' -> '#^/api/pedidos/(?P<id>[^/]+)$#'
     */
    private function agregar(string $metodo, string $ruta, callable|array $accion, array $middlewares): void
    {
        $patron = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $ruta);

        $this->rutas[] = [
            'metodo'      => $metodo,
            'patron'      => '#^' . $patron . '$#',
            'accion'      => $accion,
            'middlewares' => $middlewares,
        ];
    }

    /**
     * Busca la ruta que coincide y la ejecuta pasando por sus middlewares.
     *
     * @throws HttpExcepcion 404 si la ruta no existe, 405 si existe con otro método
     */
    public function despachar(Peticion $peticion): Respuesta
    {
        // HEAD es un GET sin cuerpo: Apache se encarga de no enviarlo
        $metodo = $peticion->metodo === 'HEAD' ? 'GET' : $peticion->metodo;
        $metodosDeLaRuta = [];

        foreach ($this->rutas as $ruta) {
            if (!preg_match($ruta['patron'], $peticion->ruta, $coincidencias)) {
                continue;
            }
            if ($ruta['metodo'] !== $metodo) {
                $metodosDeLaRuta[] = $ruta['metodo'];
                continue;
            }

            // Solo los grupos con nombre: {id} -> ['id' => '15']
            $peticion->parametros = array_filter($coincidencias, 'is_string', ARRAY_FILTER_USE_KEY);

            // Primero los globales y después los de la ruta
            return $this->ejecutar($ruta['accion'], [...$this->globales, ...$ruta['middlewares']], $peticion);
        }

        if ($metodosDeLaRuta !== []) {
            throw HttpExcepcion::metodoNoPermitido($metodosDeLaRuta);
        }
        throw HttpExcepcion::noEncontrado('Ruta no encontrada');
    }

    /**
     * Encadena los middlewares como capas de una cebolla: el primero de la lista
     * se ejecuta primero y el controlador queda en el centro.
     */
    private function ejecutar(callable|array $accion, array $middlewares, Peticion $peticion): Respuesta
    {
        $controlador = function (Peticion $peticion) use ($accion): Respuesta {
            if (is_array($accion)) {
                [$clase, $metodo] = $accion;
                $accion = [new $clase(), $metodo];
            }
            $respuesta = $accion($peticion);
            if (!$respuesta instanceof Respuesta) {
                throw new \LogicException('El controlador debe devolver un objeto Respuesta');
            }
            return $respuesta;
        };

        $cadena = array_reduce(
            array_reverse($middlewares),
            fn (callable $siguiente, Middleware|string $middleware): callable =>
                fn (Peticion $p): Respuesta => (is_string($middleware) ? new $middleware() : $middleware)
                    ->procesar($p, $siguiente),
            $controlador
        );

        return $cadena($peticion);
    }
}
