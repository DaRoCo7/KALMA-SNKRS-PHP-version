<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Validación de datos de entrada en el servidor.
 *
 * Se encadenan reglas y al final validar() devuelve los datos limpios o lanza
 * un 400 con TODOS los errores a la vez (no solo el primero):
 *
 *   $datos = (new Validador($entrada))->texto('nombre', 100)->email('email')->validar();
 *
 * Los campos opcionales que llegan vacíos quedan como null. En una edición
 * parcial (PATCH) hay que quedarse solo con los campos que envió el cliente:
 *   array_intersect_key($datos, $entrada)
 */
final class Validador
{
    /**
     * Mayor valor de una columna INTEGER de PostgreSQL. Un id más grande no
     * puede existir y, si llegara a la consulta, PostgreSQL respondería con un
     * error 22003 (fuera de rango) que acabaría en un 500.
     */
    public const ID_MAXIMO = 2_147_483_647;

    /** Los ids de la auditoría (y de los informes guardados en ella) son BIGINT */
    public const ID_MAXIMO_BIGINT = PHP_INT_MAX;

    /** Longitud máxima de ?buscar en todos los listados */
    public const BUSQUEDA_MAXIMO = 60;
    public const MENSAJE_BUSQUEDA = 'Texto de hasta 60 caracteres';

    public const MENSAJE_TALLA = 'Talla entre 34 y 50 (admite medias tallas, ej. 42.5)';

    /** @var array<string, string> campo => motivo */
    private array $errores = [];

    /** @var array<string, mixed> */
    private array $limpios = [];

    public function __construct(private readonly array $datos)
    {
    }

    public function texto(string $campo, int $maximo, bool $obligatorio = true): self
    {
        $valor = $this->leer($campo, $obligatorio);
        $error = ($valor !== null && mb_strlen($valor) > $maximo) ? "Máximo {$maximo} caracteres" : null;
        return $this->guardar($campo, $valor, $error);
    }

    public function email(string $campo): self
    {
        $valor = $this->leer($campo, true);
        $valor = $valor === null ? null : mb_strtolower($valor);
        $error = null;
        if ($valor !== null && (strlen($valor) > 150 || !filter_var($valor, FILTER_VALIDATE_EMAIL))) {
            $error = 'Email no válido';
        }
        return $this->guardar($campo, $valor, $error);
    }

    /** Mínimo 8 caracteres con letras y números. Máximo 72: bcrypt ignora lo que pase de 72 bytes */
    public function contrasena(string $campo): self
    {
        $valor = $this->leer($campo, true, recortar: false);
        $error = null;
        if ($valor !== null) {
            $error = match (true) {
                strlen($valor) < 8  => 'Mínimo 8 caracteres',
                strlen($valor) > 72 => 'Máximo 72 caracteres',
                !preg_match('/[A-Za-z]/', $valor) || !preg_match('/\d/', $valor) => 'Debe incluir letras y números',
                default => null,
            };
        }
        return $this->guardar($campo, $valor, $error);
    }

    /** Valor de una lista cerrada (ej. el estado de un pedido). Si no llega, se usa $porDefecto */
    public function opcion(string $campo, array $permitidas, ?string $porDefecto = null): self
    {
        $valor = $this->leer($campo, $porDefecto === null) ?? $porDefecto;
        $error = ($valor !== null && !in_array($valor, $permitidas, true))
            ? 'Valor no permitido: ' . implode(', ', $permitidas)
            : null;
        return $this->guardar($campo, $valor, $error);
    }

    public function patron(string $campo, string $regex, string $mensaje, bool $obligatorio = false): self
    {
        $valor = $this->leer($campo, $obligatorio);
        $error = ($valor !== null && !preg_match($regex, $valor)) ? $mensaje : null;
        return $this->guardar($campo, $valor, $error);
    }

    /** Número entero dentro de un rango. Admite 5 o "5"; rechaza 5.5, true o un array */
    public function entero(string $campo, int $minimo, int $maximo, bool $obligatorio = true): self
    {
        $valor = $this->datos[$campo] ?? null;
        if ($valor === null || $valor === '') {
            return $this->guardar($campo, null, $obligatorio ? 'Obligatorio' : null);
        }
        $entero = is_bool($valor) || is_array($valor)
            ? false
            : filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimo, 'max_range' => $maximo]]);

        return $entero === false
            ? $this->guardar($campo, null, "Número entero entre {$minimo} y {$maximo}")
            : $this->guardar($campo, $entero, null);
    }

    /**
     * Importe con hasta 2 decimales y punto decimal (119.99), dentro de un rango.
     * Rechaza la coma decimal, "nan", "inf" y la notación científica.
     */
    public function importe(string $campo, float $minimo, float $maximo, bool $obligatorio = true): self
    {
        $valor = $this->datos[$campo] ?? null;
        if ($valor === null || $valor === '') {
            return $this->guardar($campo, null, $obligatorio ? 'Obligatorio' : null);
        }
        $texto = is_int($valor) || is_float($valor) ? (string) $valor : $valor;
        $valido = is_string($texto) && preg_match('/^\d+(\.\d{1,2})?$/', $texto)
            && (float) $texto >= $minimo && (float) $texto <= $maximo;

        return $valido
            ? $this->guardar($campo, (float) $texto, null)
            : $this->guardar($campo, null, "Importe entre {$minimo} y {$maximo} con hasta 2 decimales (ej. 119.99)");
    }

    /** true/false (también 1/0 y "true"/"false"). Si no llega, se usa $porDefecto */
    public function booleano(string $campo, ?bool $porDefecto = null): self
    {
        $valor = $this->datos[$campo] ?? null;
        if ($valor === null || $valor === '') {
            return $this->guardar($campo, $porDefecto, null);
        }
        $booleano = is_scalar($valor) ? filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        return $booleano === null
            ? $this->guardar($campo, null, 'Valores: true o false')
            : $this->guardar($campo, $booleano, null);
    }

    /**
     * @return array<string, mixed> datos limpios
     * @throws HttpExcepcion 400 con la lista de errores por campo
     */
    public function validar(): array
    {
        if ($this->errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Hay datos no válidos', $this->errores);
        }
        return $this->limpios;
    }

    // =========================================================================
    // Valores sueltos de la URL (parámetros de ruta y filtros de los listados).
    // No van encadenados: cada servicio decide el mensaje y si el error es un
    // 400 (filtro mal escrito) o un 404 (esa ruta no puede existir).
    // =========================================================================

    /**
     * Identificador positivo dentro del rango de la columna, o null. Rechaza
     * true y los arrays (?categoria[]=1), que filter_var convertiría o dejaría pasar.
     */
    public static function id(mixed $valor, int $maximo = self::ID_MAXIMO): ?int
    {
        if (is_bool($valor) || !is_scalar($valor)) {
            return null;
        }
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximo]]);
        return $entero === false ? null : $entero;
    }

    /**
     * El {id} de la ruta: si no es un entero positivo en rango, ese recurso no
     * puede existir y se responde 404 sin consultar la BD.
     *
     * @throws HttpExcepcion 404 con el mensaje de cada recurso
     */
    public static function idRuta(string $id, string $noEncontrado, int $maximo = self::ID_MAXIMO): int
    {
        return self::id($id, $maximo) ?? throw HttpExcepcion::noEncontrado($noEncontrado);
    }

    /** Texto de ?buscar sin espacios a los lados, o null si no es un texto de hasta 60 caracteres */
    public static function busqueda(mixed $valor): ?string
    {
        return is_string($valor) && mb_strlen(trim($valor)) <= self::BUSQUEDA_MAXIMO ? trim($valor) : null;
    }

    /**
     * Talla válida (34 a 50, enteras o medias) o null. Admite 42.5 o "42.5";
     * rechaza true, un array o 42.3.
     */
    public static function talla(mixed $valor): ?float
    {
        if (is_bool($valor) || !is_scalar($valor)) {
            return null;
        }
        $talla = filter_var($valor, FILTER_VALIDATE_FLOAT);
        $valida = $talla !== false && $talla >= 34 && $talla <= 50 && fmod($talla * 2, 1.0) === 0.0;
        return $valida ? (float) $talla : null;
    }

    /** Lee un campo como texto. Un array o un objeto donde se espera texto es un error */
    private function leer(string $campo, bool $obligatorio, bool $recortar = true): ?string
    {
        $valor = $this->datos[$campo] ?? null;
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if ($valor !== null && !is_string($valor)) {
            $this->errores[$campo] = 'Debe ser un texto';
            return null;
        }
        if ($recortar && $valor !== null) {
            $valor = trim($valor);
        }
        if ($valor === null || $valor === '') {
            if ($obligatorio) {
                $this->errores[$campo] = 'Obligatorio';
            }
            return null;
        }
        return $valor;
    }

    private function guardar(string $campo, mixed $valor, ?string $error): self
    {
        if ($error !== null) {
            $this->errores[$campo] = $error;
        } elseif (!isset($this->errores[$campo])) {
            $this->limpios[$campo] = $valor;
        }
        return $this;
    }
}
