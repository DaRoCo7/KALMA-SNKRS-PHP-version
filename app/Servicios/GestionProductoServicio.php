<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\CatalogoInternoRepositorio;

/**
 * Reglas del catálogo interno: dar de alta, editar y desactivar zapatillas.
 *
 * Una zapatilla nunca se borra: los pedidos antiguos la referencian (a través
 * de sus tallas) y los movimientos de stock también. Se desactiva y deja de
 * salir en el catálogo público.
 *
 * El alta (darDeAlta) la reutiliza AlbaranServicio cuando un albarán trae una
 * zapatilla que todavía no está en el catálogo.
 */
final class GestionProductoServicio
{
    /** La tienda solo vende Nike (Jordan es una categoría de Nike, ver la semilla) */
    public const MARCA = 'Nike';

    /** Código de estilo de Nike: 6 letras o cifras, guion y 3 cifras (CW2288-111) */
    public const PATRON_CODIGO = '/^[A-Z0-9]{6}-[0-9]{3}$/';

    public const PRECIO_MINIMO = 0.01;
    public const PRECIO_MAXIMO = 9999.99;

    /**
     * El slug es nombre + código (11 caracteres con el guion) y la columna admite
     * 160: con 140 caracteres de nombre el slug siempre cabe. La columna nombre
     * admite 150, pero un nombre más largo haría fallar el INSERT con un 500.
     */
    public const NOMBRE_MAXIMO = 140;
    public const COLORWAY_MAXIMO = 100;

    /** Mayor valor de una columna INTEGER de PostgreSQL */
    private const ID_MAXIMO = 2_147_483_647;
    private const MAX_TALLAS = 30;
    private const NO_ENCONTRADO = 'Producto no encontrado';
    private const DATOS_NO_VALIDOS = 'Hay datos no válidos';
    private const MENSAJE_TALLA = 'Talla entre 34 y 50 (admite medias tallas, ej. 42.5)';

    public function __construct(
        private readonly CatalogoInternoRepositorio $catalogo = new CatalogoInternoRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    // =========================================================================
    // Consultas
    // =========================================================================

    /** ?buscar=dunk&activo=false · incluye los productos desactivados */
    public function listar(array $query, Paginacion $paginacion): array
    {
        $filtros = $this->filtros($query);
        $total = $this->catalogo->contar($filtros);
        $filas = $this->catalogo->listar($filtros, $paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map($this->resumen(...), $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /** @throws HttpExcepcion 404 */
    public function ver(string $id): array
    {
        $producto = $this->catalogo->buscarPorId($this->comprobarId($id))
            ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
        return $this->detalle($producto);
    }

    // =========================================================================
    // Alta, edición y desactivación
    // =========================================================================

    /**
     * POST /api/gestion/productos · alta de una zapatilla y sus tallas (con stock 0:
     * las unidades entran con un albarán o un ajuste de stock, que dejan su movimiento).
     *
     * @throws HttpExcepcion 400 datos no válidos · 409 el código de estilo ya existe
     */
    public function crear(array $usuario, array $entrada, ?string $ip): array
    {
        $datos = $this->validar($entrada, parcial: false);
        $marcaId = $this->marcaId();

        try {
            $nuevo = BaseDatos::transaccion(
                fn (): array => $this->darDeAlta($marcaId, $datos, $datos['tallas'], $usuario['id'], $ip)
            );
        } catch (\PDOException $error) {
            if (!self::esDuplicado($error)) {
                throw $error;
            }
            // PostgreSQL comprueba el UNIQUE del slug antes que el del código, así que el
            // mensaje del error no sirve para saber cuál ha chocado: se pregunta a la BD
            $codigo = $datos['codigo_marca'];
            throw $this->catalogo->buscarPorCodigos($marcaId, [$codigo]) !== []
                ? HttpExcepcion::conflicto("Ya existe una zapatilla con el código {$codigo}", ['codigo_marca' => $codigo])
                : HttpExcepcion::conflicto('Ya existe un producto con la misma URL (slug): cambia el nombre', ['nombre' => $datos['nombre']]);
        }

        Log::info('Producto creado', ['producto_id' => $nuevo['id'], 'codigo_marca' => $datos['codigo_marca']]);
        return $this->detalle($this->catalogo->buscarPorId($nuevo['id']));
    }

    /**
     * PATCH /api/gestion/productos/{id} · cambia solo los campos enviados.
     *
     * El código de estilo no se cambia: identifica la zapatilla del fabricante y
     * de él salen el slug, la imagen y el RFID. Si estuviera mal, se desactiva
     * esta zapatilla y se da de alta otra. El slug tampoco cambia al renombrar,
     * para no romper los enlaces que ya se hayan compartido.
     *
     * Cambiar el precio NO afecta a los pedidos ya hechos: cada línea de pedido
     * guardó una copia del precio en el momento de la compra (lineas_pedido.precio_unitario).
     *
     * @throws HttpExcepcion 400 datos no válidos o ningún campo · 404 no existe
     */
    public function editar(array $usuario, string $id, array $entrada, ?string $ip): array
    {
        $productoId = $this->comprobarId($id);
        if (array_key_exists('codigo_marca', $entrada)) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, [
                'codigo_marca' => 'No se puede cambiar: si es incorrecto, desactiva el producto y da de alta otro',
            ]);
        }
        $datos = $this->validar($entrada, parcial: true);

        BaseDatos::transaccion(function () use ($productoId, $datos, $usuario, $ip): void {
            $actual = $this->catalogo->bloquear($productoId) ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);

            // Solo se guarda (y se audita) lo que de verdad cambia
            $cambios = array_filter(
                $datos,
                static fn (mixed $valor, string $campo): bool => !self::igual($actual[$campo], $valor),
                ARRAY_FILTER_USE_BOTH
            );
            if ($cambios === []) {
                return;
            }
            $this->catalogo->actualizar($productoId, $cambios);

            $detalle = ['codigo_marca' => $actual['codigo_marca'], 'campos' => array_keys($cambios)];
            foreach (['precio', 'activo'] as $campo) {
                if (array_key_exists($campo, $cambios)) {
                    $antes = $campo === 'precio' ? (float) $actual[$campo] : $actual[$campo];
                    $detalle[$campo] = ['antes' => $antes, 'despues' => $cambios[$campo]];
                }
            }
            $this->auditoria->registrar($usuario['id'], 'producto.editar', 'producto', $productoId, 'exito', $detalle, $ip);
        });

        return $this->detalle($this->catalogo->buscarPorId($productoId));
    }

    /**
     * DELETE /api/gestion/productos/{id} · NO borra: pone activo = false.
     * Las líneas de pedido y los movimientos de stock siguen apuntando a sus
     * tallas, y borrarla dejaría esos registros sin la zapatilla a la que se refieren.
     * Es idempotente: desactivar una zapatilla ya desactivada también responde 204.
     *
     * @throws HttpExcepcion 404
     */
    public function desactivar(array $usuario, string $id, ?string $ip): void
    {
        $productoId = $this->comprobarId($id);

        BaseDatos::transaccion(function () use ($productoId, $usuario, $ip): void {
            $actual = $this->catalogo->bloquear($productoId) ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
            $this->catalogo->actualizar($productoId, ['activo' => false]);
            $this->auditoria->registrar($usuario['id'], 'producto.desactivar', 'producto', $productoId, 'exito', [
                'codigo_marca'  => $actual['codigo_marca'],
                'estaba_activo' => $actual['activo'],
            ], $ip);
        });
    }

    // =========================================================================
    // Piezas que también usa AlbaranServicio
    // =========================================================================

    /**
     * Crea la zapatilla y sus tallas y deja el alta en la auditoría.
     * Debe llamarse DENTRO de BaseDatos::transaccion(): el alta del producto y la
     * de sus tallas van juntas (no puede quedar una zapatilla sin sus tallas a medias).
     *
     * @param list<float> $tallas
     * @param array<string, mixed> $origen datos extra para la auditoría (ej. el albarán)
     * @return array{id: int, slug: string, variantes: array<string, int>} variantes: "42.5" => id
     * @throws \PDOException 23505 si el código o el slug ya existen
     */
    public function darDeAlta(int $marcaId, array $datos, array $tallas, int $usuarioId, ?string $ip, array $origen = []): array
    {
        $producto = $this->catalogo->crear($marcaId, $datos);

        $variantes = [];
        foreach ($tallas as $talla) {
            $variantes[self::claveTalla($talla)] = $this->catalogo->crearTalla($producto['id'], $talla);
        }

        $this->auditoria->registrar($usuarioId, 'producto.crear', 'producto', $producto['id'], 'exito', [
            'codigo_marca'   => $datos['codigo_marca'],
            'nombre'         => $datos['nombre'],
            'precio'         => $datos['precio'],
            'personalizable' => $datos['personalizable'],
            'tallas'         => $tallas,
        ] + $origen, $ip);

        return $producto + ['variantes' => $variantes];
    }

    /** @throws \RuntimeException si falta la marca en la BD (error de instalación: 500) */
    public function marcaId(): int
    {
        return $this->catalogo->marcaId(self::MARCA)
            ?? throw new \RuntimeException('No existe la marca ' . self::MARCA . ' en la tabla marcas');
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

    /**
     * Clave de una talla para indexar arrays: 42 y "42.0" (como la devuelve
     * PostgreSQL) son la misma talla. Un float no sirve como clave de array en PHP.
     */
    public static function claveTalla(float|string $talla): string
    {
        return sprintf('%.1f', (float) $talla);
    }

    /**
     * ¿El error es un 23505 (violación de UNIQUE)? Es el único error de la BD que
     * se convierte en un 409: cualquier otro se relanza y acaba en un 500 con su referencia.
     */
    public static function esDuplicado(\PDOException $error): bool
    {
        return ($error->errorInfo[0] ?? $error->getCode()) === '23505';
    }

    // =========================================================================
    // Validación
    // =========================================================================

    /**
     * Valida el cuerpo del alta (son obligatorios todos los campos salvo descripcion, personalizable
     * y tallas) o de la edición (solo los campos enviados, que no pueden llegar vacíos).
     *
     * Primero se comprueba la forma de los datos y después, ya con datos limpios,
     * que la categoría y el color existen en la BD.
     *
     * @throws HttpExcepcion 400 con todos los errores
     */
    private function validar(array $entrada, bool $parcial): array
    {
        // En una edición, un campo enviado es obligatorio: "nombre": "" no puede dejar la zapatilla sin nombre
        $exigido = static fn (string $campo): bool => !$parcial || array_key_exists($campo, $entrada);

        if (isset($entrada['codigo_marca']) && is_string($entrada['codigo_marca'])) {
            $entrada['codigo_marca'] = strtoupper(trim($entrada['codigo_marca']));
        }

        $validador = new Validador($entrada);
        if (!$parcial) {
            $validador->patron('codigo_marca', self::PATRON_CODIGO, 'Código de estilo de Nike, ej. CW2288-111', obligatorio: true);
        }
        $validador
            ->texto('nombre', self::NOMBRE_MAXIMO, $exigido('nombre'))
            ->texto('colorway', self::COLORWAY_MAXIMO, $exigido('colorway'))
            ->texto('descripcion', 2000, obligatorio: false)
            ->importe('precio', self::PRECIO_MINIMO, self::PRECIO_MAXIMO, $exigido('precio'))
            ->entero('categoria_id', 1, self::ID_MAXIMO, $exigido('categoria_id'))
            ->entero('color_id', 1, self::ID_MAXIMO, $exigido('color_id'))
            ->booleano('personalizable', $parcial ? null : false);
        if ($parcial) {
            $validador->booleano('activo');
        }

        $errores = [];
        $datos = [];
        try {
            $datos = $validador->validar();
        } catch (HttpExcepcion $error) {
            $errores = $error->detalles['errores'] ?? [];
        }

        if ($parcial) {
            // booleano() deja null un valor vacío sin dar error: en una edición no vale
            foreach (['personalizable', 'activo'] as $campo) {
                if (array_key_exists($campo, $entrada) && ($datos[$campo] ?? null) === null && !isset($errores[$campo])) {
                    $errores[$campo] = 'Valores: true o false';
                }
            }
        } else {
            $datos['tallas'] = $this->validarTallas($entrada['tallas'] ?? null, $errores);
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, $errores);
        }

        if ($parcial) {
            // Solo los campos que envió el cliente: el resto no se toca
            $datos = array_intersect_key($datos, $entrada);
            if ($datos === []) {
                throw HttpExcepcion::peticionIncorrecta(
                    'No se ha enviado ningún campo que se pueda cambiar: ' . implode(', ', CatalogoInternoRepositorio::EDITABLES)
                );
            }
        }

        $this->comprobarReferencias($datos);
        return $datos;
    }

    /**
     * Lista de tallas sin repetir, ordenada: [39, 40, 42.5]. Si no llega, [].
     *
     * @param array<string, string> $errores se añaden aquí con clave tallas.<posición>
     * @return list<float>
     */
    private function validarTallas(mixed $tallas, array &$errores): array
    {
        if ($tallas === null) {
            return [];
        }
        if (!is_array($tallas) || !array_is_list($tallas) || count($tallas) > self::MAX_TALLAS) {
            $errores['tallas'] = 'Lista de hasta ' . self::MAX_TALLAS . ' tallas, ej. [40, 42.5]';
            return [];
        }

        $limpias = [];
        foreach ($tallas as $posicion => $valor) {
            $talla = self::talla($valor);
            if ($talla === null) {
                $errores["tallas.{$posicion}"] = self::MENSAJE_TALLA;
            } elseif (isset($limpias[self::claveTalla($talla)])) {
                $errores["tallas.{$posicion}"] = 'Talla repetida';
            } else {
                $limpias[self::claveTalla($talla)] = $talla;
            }
        }

        sort($limpias);
        return array_values($limpias);
    }

    /** @throws HttpExcepcion 400 si la categoría o el color no existen */
    private function comprobarReferencias(array $datos): void
    {
        $errores = [];
        if (isset($datos['categoria_id']) && !in_array($datos['categoria_id'], array_map('intval', array_column($this->catalogo->categorias(), 'id')), true)) {
            $errores['categoria_id'] = 'No existe esa categoría';
        }
        if (isset($datos['color_id']) && !in_array($datos['color_id'], array_map('intval', array_column($this->catalogo->colores(), 'id')), true)) {
            $errores['color_id'] = 'No existe ese color';
        }
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta(self::DATOS_NO_VALIDOS, $errores);
        }
    }

    /** ?buscar= (nombre, colorway o código) y ?activo=true|false */
    private function filtros(array $query): array
    {
        $filtros = [];
        $errores = [];

        $buscar = $query['buscar'] ?? '';
        if ($buscar !== '') {
            if (is_string($buscar) && mb_strlen(trim($buscar)) <= 60) {
                $filtros['buscar'] = trim($buscar);
            } else {
                $errores['buscar'] = 'Texto de hasta 60 caracteres';
            }
        }

        $activo = $query['activo'] ?? '';
        if ($activo !== '') {
            $valor = is_scalar($activo) ? filter_var($activo, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
            if ($valor === null) {
                $errores['activo'] = 'Valores: true o false';
            } else {
                $filtros['activo'] = $valor;
            }
        }

        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        return $filtros;
    }

    /** Un id que no es un entero positivo no puede existir: 404 sin consultar la BD */
    private function comprobarId(string $id): int
    {
        $entero = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::ID_MAXIMO]]);
        if ($entero === false) {
            throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
        }
        return $entero;
    }

    /** ¿El valor de la BD y el nuevo son el mismo? (PostgreSQL devuelve los NUMERIC como texto) */
    private static function igual(mixed $actual, mixed $nuevo): bool
    {
        return match (true) {
            is_float($nuevo) => abs((float) $actual - $nuevo) < 0.001,
            is_int($nuevo)   => (int) $actual === $nuevo,
            default          => $actual === $nuevo,
        };
    }

    // =========================================================================
    // Formato de salida
    // =========================================================================

    /** Datos de un producto en el listado interno */
    private function resumen(array $p): array
    {
        return [
            'id'                  => (int) $p['id'],
            'codigo_marca'        => $p['codigo_marca'],
            'nombre'              => $p['nombre'],
            'colorway'            => $p['colorway'],
            'slug'                => $p['slug'],
            'precio'              => (float) $p['precio'],
            'personalizable'      => $p['personalizable'],
            'activo'              => $p['activo'],
            'imagen'              => $p['imagen'] === null ? null : '/' . $p['imagen'],
            'categoria'           => ['id' => (int) $p['categoria_id'], 'nombre' => $p['categoria']],
            'color'               => ['id' => (int) $p['color_id'], 'nombre' => $p['color'], 'hex' => $p['codigo_hex']],
            'stock_total'         => (int) $p['stock_total'],
            'num_tallas'          => (int) $p['num_tallas'],
            'fecha_actualizacion' => self::fecha($p['fecha_actualizacion']),
        ];
    }

    /** Producto con descripción y todas sus tallas (también las desactivadas), con su RFID */
    private function detalle(array $p): array
    {
        return $this->resumen($p) + [
            'descripcion'    => $p['descripcion'],
            'fecha_creacion' => self::fecha($p['fecha_creacion']),
            'variantes'      => array_map(static fn (array $v): array => [
                'id'           => (int) $v['id'],
                'talla'        => (float) $v['talla'],
                'stock'        => (int) $v['stock'],
                'stock_minimo' => (int) $v['stock_minimo'],
                'rfid_tienda'  => $v['rfid_tienda'],
                'activo'       => $v['activo'],
            ], $this->catalogo->variantes((int) $p['id'])),
        ];
    }

    /** Fecha de PostgreSQL (2026-11-08 19:15:00+01) en ISO 8601 (2026-11-08T19:15:00+01:00) */
    private static function fecha(?string $valor): ?string
    {
        return $valor === null ? null : (new \DateTimeImmutable($valor))->format(DATE_ATOM);
    }
}
