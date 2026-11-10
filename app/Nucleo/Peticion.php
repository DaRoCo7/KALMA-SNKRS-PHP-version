<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Petición HTTP que llega al servidor.
 *
 * Reúne en un solo objeto lo que PHP reparte entre $_SERVER, $_GET, $_POST y
 * php://input, para que los controladores no toquen las variables globales.
 */
final class Peticion
{
    /** Tamaño máximo del cuerpo JSON (1 MB): límite razonable de entrada */
    private const TAMANO_MAXIMO = 1_048_576;

    /**
     * @param array<string, mixed>  $query       Parámetros de la URL (?pagina=2)
     * @param array<string, string> $cabeceras   Cabeceras en minúsculas
     * @param array<string, mixed>  $formulario  Campos de un formulario HTML ($_POST)
     * @param array<string, string> $parametros  Parámetros de la ruta, ej. {id}
     * @param string                $ip          IP real del cliente (para el rate limiting y la auditoría)
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $query = [],
        public readonly array $cabeceras = [],
        private readonly string $cuerpo = '',
        private readonly array $formulario = [],
        public array $parametros = [],
        public readonly string $ip = '0.0.0.0',
    ) {
    }

    /** Construye la petición a partir de las variables globales de PHP */
    public static function desdeGlobales(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Los formularios HTML solo envían GET y POST: el campo oculto _metodo
        // permite simular PUT, PATCH o DELETE desde un formulario.
        if ($metodo === 'POST' && isset($_POST['_metodo'])) {
            $simulado = strtoupper((string) $_POST['_metodo']);
            if (in_array($simulado, ['PUT', 'PATCH', 'DELETE'], true)) {
                $metodo = $simulado;
            }
        }

        // '/catalogo/' y '/catalogo' son la misma ruta
        $ruta = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $ruta = '/' . trim($ruta, '/');

        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr($clave, 5)))] = (string) $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $cabeceras['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        // Detrás del proxy, REMOTE_ADDR es la IP de Nginx (red interna de Docker).
        // Solo en ese caso nos fiamos de X-Real-IP, que Nginx rellena con la IP real;
        // si la petición no viniera de una red privada, la cabecera se ignora.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $ipReal = $cabeceras['x-real-ip'] ?? '';
        if (filter_var($ipReal, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            $ip = $ipReal;
        }

        return new self(
            $metodo, $ruta, $_GET, $cabeceras, (string) file_get_contents('php://input'), $_POST, ip: $ip
        );
    }

    /** Las rutas que empiezan por /api responden siempre en JSON */
    public function esApi(): bool
    {
        return $this->ruta === '/api' || str_starts_with($this->ruta, '/api/');
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    public function parametro(string $nombre): ?string
    {
        return $this->parametros[$nombre] ?? null;
    }

    /** Un campo concreto de un formulario HTML (ej. el token CSRF oculto) */
    public function campoFormulario(string $nombre): mixed
    {
        return $this->formulario[$nombre] ?? null;
    }

    /**
     * Cuerpo de la petición decodificado como objeto JSON.
     *
     * @return array<string, mixed>
     * @throws HttpExcepcion 413, 415 o 400 si el cuerpo no es válido
     */
    public function json(): array
    {
        if ($this->cuerpo === '') {
            return [];
        }
        if (strlen($this->cuerpo) > self::TAMANO_MAXIMO) {
            throw HttpExcepcion::demasiadoGrande();
        }
        if (!str_contains($this->cabecera('content-type') ?? '', 'application/json')) {
            throw HttpExcepcion::tipoNoSoportado('El cuerpo debe enviarse como application/json');
        }

        try {
            $datos = json_decode($this->cuerpo, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpExcepcion::peticionIncorrecta('El cuerpo no es un JSON válido');
        }
        if (!is_array($datos)) {
            throw HttpExcepcion::peticionIncorrecta('El cuerpo debe ser un objeto JSON');
        }

        return $datos;
    }

    /**
     * Datos enviados por el cliente, vengan de la API (JSON) o de un formulario.
     *
     * @return array<string, mixed>
     */
    public function entrada(): array
    {
        return $this->esApi() ? $this->json() : $this->formulario;
    }
}
