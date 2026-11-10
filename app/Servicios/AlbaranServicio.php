<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\CatalogoInternoRepositorio;
use App\Repositorios\StockRepositorio;

/**
 * Carga de un albarán del proveedor en CSV: suma el stock de las tallas que
 * llegan y da de alta las zapatillas y tallas que todavía no están en el catálogo.
 *
 *   codigo_marca,nombre,colorway,categoria,color,precio,talla,cantidad
 *   CW2288-111,,,,,,42,10
 *   DD1391-102,Nike Dunk Low Retro,White/University Blue,Lifestyle,Azul,119.99,41,4
 *
 * El albarán entra entero o no entra: se valida el archivo completo ANTES de
 * escribir y, si una sola fila está mal, se rechaza con el error de cada fila.
 * Así nunca queda medio albarán cargado y el almacén puede corregir el archivo
 * y volver a enviarlo sin duplicar unidades.
 */
final class AlbaranServicio
{
    public const COLUMNAS = ['codigo_marca', 'nombre', 'colorway', 'categoria', 'color', 'precio', 'talla', 'cantidad'];

    /** Columnas que solo hacen falta si la zapatilla es nueva (si ya existe, se ignoran) */
    private const COLUMNAS_ALTA = ['nombre', 'colorway', 'categoria', 'color', 'precio'];

    private const MAX_FILAS = 500;
    private const MAX_UNIDADES_FILA = 1000;
    private const PATRON_REFERENCIA = '/^[A-Za-z0-9\/_-]{1,50}$/';
    private const ALBARAN_NO_VALIDO = 'El albarán tiene errores: no se ha cargado ninguna fila';

    public function __construct(
        private readonly CatalogoInternoRepositorio $catalogo = new CatalogoInternoRepositorio(),
        private readonly StockRepositorio $stock = new StockRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly GestionProductoServicio $productos = new GestionProductoServicio(),
    ) {
    }

    /**
     * @param mixed $referencia número del albarán del proveedor (ej. ALB-2026-0042), de ?referencia=
     * @throws HttpExcepcion 400 archivo o filas no válidos · 409 una zapatilla nueva se dio de alta mientras tanto
     */
    public function cargar(array $usuario, string $csv, mixed $referencia, ?string $ip): array
    {
        $referencia = $this->referencia($referencia);
        $marcaId = $this->productos->marcaId();

        // 1. Leer y validar el archivo sin escribir nada
        [$filas, $errores] = $this->leer($csv);
        $existentes = $this->catalogo->buscarPorCodigos($marcaId, array_values(array_unique(array_column($filas, 'codigo_marca'))));
        $zapatillas = $this->agrupar($filas, $existentes, $errores);

        if ($errores !== []) {
            ksort($errores, SORT_NATURAL);
            throw HttpExcepcion::peticionIncorrecta(self::ALBARAN_NO_VALIDO, $errores);
        }

        // 2. Escribir en UNA transacción: o entra el albarán entero o no entra nada
        try {
            $resumen = BaseDatos::transaccion(
                fn (): array => $this->escribir($zapatillas, $marcaId, $usuario['id'], $ip, $referencia, count($filas))
            );
        } catch (\PDOException $error) {
            // Un 23505 aquí solo puede significar que otra petición dio de alta una de las
            // zapatillas nuevas entre la validación y la escritura: se pide volver a enviarlo
            if (GestionProductoServicio::esDuplicado($error)) {
                throw HttpExcepcion::conflicto(
                    'Una zapatilla del albarán se ha dado de alta mientras se cargaba: vuelve a enviarlo',
                    ['referencia' => $referencia]
                );
            }
            throw $error;
        }

        Log::info('Albarán cargado', ['referencia' => $referencia, 'unidades' => $resumen['unidades']]);

        if ($resumen['desactivados'] !== []) {
            $resumen['aviso'] = 'Se ha sumado el stock de zapatillas desactivadas, pero siguen sin venderse. '
                . 'Para volver a venderlas: PATCH /api/gestion/productos/{id} con {"activo": true}';
        }
        return $resumen;
    }

    /**
     * Da de alta lo que falta y suma las unidades. Se llama dentro de la transacción.
     *
     * @param array<string, array> $zapatillas agrupadas por código (ver agrupar())
     */
    private function escribir(array $zapatillas, int $marcaId, int $usuarioId, ?string $ip, string $referencia, int $filas): array
    {
        // Se bloquean las zapatillas que ya existen: si dos albaranes traen la misma
        // talla nueva, el segundo espera y la encuentra creada en vez de chocar
        $bloqueadas = $this->catalogo->buscarPorCodigos($marcaId, array_keys($zapatillas), bloquear: true);

        $resumen = [
            'referencia'             => $referencia,
            'filas'                  => $filas,
            'unidades'               => 0,
            'productos_nuevos'       => [],
            'productos_actualizados' => [],
            'tallas_nuevas'          => 0,
            'desactivados'           => [],
        ];

        foreach ($zapatillas as $codigo => $zapatilla) {
            $existente = $bloqueadas[$codigo] ?? null;

            if ($existente === null) {
                // Zapatilla nueva: se da de alta NO personalizable. Que admita
                // bordado lo decide después la administración con un PATCH
                $alta = $this->productos->darDeAlta(
                    $marcaId,
                    $zapatilla['alta'] + ['codigo_marca' => $codigo, 'descripcion' => null, 'personalizable' => false],
                    array_column($zapatilla['tallas'], 'talla'),
                    $usuarioId,
                    $ip,
                    ['origen' => 'albaran', 'referencia' => $referencia]
                );
                $productoId = $alta['id'];
                $variantes = $alta['variantes'];
                $resumen['productos_nuevos'][] = $codigo;
                $resumen['tallas_nuevas'] += count($variantes);
            } else {
                $productoId = (int) $existente['id'];
                $variantes = $this->variantesPorTalla($productoId);
                $resumen['productos_actualizados'][] = $codigo;
                if (!$existente['activo']) {
                    // Las unidades existen físicamente y se cuentan, pero la zapatilla
                    // sigue sin venderse: reactivarla es decisión de la administración
                    $resumen['desactivados'][] = $codigo;
                }
            }

            $resumen['tallas_nuevas'] += $this->darEntrada($productoId, $variantes, $zapatilla['tallas'], $usuarioId, "Albarán {$referencia}");
            $resumen['unidades'] += array_sum(array_column($zapatilla['tallas'], 'cantidad'));
        }

        $this->auditoria->registrar($usuarioId, 'albaran.cargar', 'albaran', null, 'exito', $resumen, $ip);
        return $resumen;
    }

    /**
     * Suma las unidades de cada talla; las tallas que el producto no tenía se
     * crean antes (con stock 0 y su RFID).
     *
     * @param array<string, int> $variantes "42.5" => id de la talla
     * @return int tallas creadas
     */
    private function darEntrada(int $productoId, array $variantes, array $tallas, int $usuarioId, string $motivo): int
    {
        $creadas = 0;
        foreach ($tallas as $clave => $linea) {
            if (!isset($variantes[$clave])) {
                $variantes[$clave] = $this->catalogo->crearTalla($productoId, $linea['talla']);
                $creadas++;
            }
            // Siempre por mover(): cada entrada deja su movimiento con la referencia del albarán
            $this->stock->mover($variantes[$clave], $linea['cantidad'], 'entrada', null, $usuarioId, $motivo);
        }
        return $creadas;
    }

    /** @return array<string, int> tallas que ya tiene el producto: "42.5" => id */
    private function variantesPorTalla(int $productoId): array
    {
        $variantes = [];
        foreach ($this->catalogo->variantes($productoId) as $v) {
            $variantes[GestionProductoServicio::claveTalla($v['talla'])] = (int) $v['id'];
        }
        return $variantes;
    }

    /**
     * Separa el CSV en filas con clave = nombre de columna y comprueba cada fila
     * por separado (código, talla y cantidad). Los datos de alta se comprueban
     * después, cuando se sabe qué zapatillas son nuevas.
     *
     * @return array{0: list<array>, 1: array<string, string>} filas y errores
     * @throws HttpExcepcion 400 si la cabecera o el tamaño del archivo no son válidos
     */
    private function leer(string $csv): array
    {
        // El BOM lo añade Excel al guardar en "CSV UTF-8": no forma parte de la primera columna
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw HttpExcepcion::peticionIncorrecta('El archivo debe estar en UTF-8');
        }

        $lineas = preg_split('/\r\n|\n|\r/', $csv);
        $cabecera = $lineas[0];

        // Excel en español guarda los CSV con punto y coma (la coma es el separador decimal)
        $separador = substr_count($cabecera, ';') > substr_count($cabecera, ',') ? ';' : ',';
        $columnas = array_map(static fn (?string $c): string => strtolower(trim((string) $c)), str_getcsv($cabecera, $separador, '"', ''));

        $faltan = array_diff(self::COLUMNAS, $columnas);
        if ($faltan !== [] || count(array_unique($columnas)) !== count($columnas)) {
            throw HttpExcepcion::peticionIncorrecta('La cabecera del albarán no es válida', [
                'cabecera' => 'Debe ser: ' . implode(',', self::COLUMNAS)
                    . ($faltan === [] ? ' (sin columnas repetidas)' : '. Faltan: ' . implode(', ', $faltan)),
            ]);
        }

        $filas = [];
        $errores = [];
        $leidas = 0;
        foreach (array_slice($lineas, 1, preserve_keys: true) as $indice => $linea) {
            if (trim($linea) === '') {
                continue;   // líneas en blanco (ej. la del final del archivo)
            }
            $numero = $indice + 1;   // número de línea del archivo: la cabecera es la 1
            if (++$leidas > self::MAX_FILAS) {
                throw HttpExcepcion::peticionIncorrecta('El albarán tiene demasiadas filas', [
                    'archivo' => 'Máximo ' . self::MAX_FILAS . ' filas: divídelo en varios albaranes',
                ]);
            }

            $valores = str_getcsv($linea, $separador, '"', '');
            if (count($valores) !== count($columnas)) {
                $errores["fila.{$numero}"] = 'Tiene ' . count($valores) . ' columnas y la cabecera ' . count($columnas);
                continue;
            }
            $fila = array_combine($columnas, array_map(static fn (?string $v): string => trim((string) $v), $valores));
            $fila['codigo_marca'] = strtoupper($fila['codigo_marca']);
            // Con Excel en español las tallas y los precios llegan con coma decimal (42,5)
            $fila['talla'] = str_replace(',', '.', $fila['talla']);
            $fila['precio'] = str_replace(',', '.', $fila['precio']);

            $limpia = $this->validarFila($fila, $numero, $errores);
            if ($limpia !== null) {
                $filas[] = $limpia + ['linea' => $numero, 'fila' => $fila];
            }
        }

        if ($leidas === 0) {
            throw HttpExcepcion::peticionIncorrecta('El albarán no tiene filas', ['archivo' => 'Añade al menos una fila después de la cabecera']);
        }
        return [$filas, $errores];
    }

    /**
     * Código, talla y cantidad: obligatorios en todas las filas.
     * @return array{codigo_marca: string, talla: float, cantidad: int}|null null si la fila tiene errores
     */
    private function validarFila(array $fila, int $numero, array &$errores): ?array
    {
        $erroresFila = [];
        $datos = [];
        try {
            $datos = (new Validador($fila))
                ->patron('codigo_marca', GestionProductoServicio::PATRON_CODIGO, 'Código de estilo de Nike, ej. CW2288-111', obligatorio: true)
                ->entero('cantidad', 1, self::MAX_UNIDADES_FILA)
                ->validar();
        } catch (HttpExcepcion $error) {
            $erroresFila = $error->detalles['errores'] ?? [];
        }

        $talla = GestionProductoServicio::talla($fila['talla']);
        if ($talla === null) {
            $erroresFila['talla'] = $fila['talla'] === '' ? 'Obligatorio' : 'Talla entre 34 y 50 (admite medias tallas, ej. 42.5)';
        }

        foreach ($erroresFila as $campo => $motivo) {
            $errores["fila.{$numero}.{$campo}"] = $motivo;
        }
        return $erroresFila === [] ? $datos + ['talla' => $talla] : null;
    }

    /**
     * Agrupa las filas por zapatilla y talla (si una talla aparece varias veces,
     * se suman sus unidades) y valida los datos de alta de las zapatillas nuevas.
     *
     * Los datos de una zapatilla nueva se toman de su PRIMERA fila, que debe
     * traerlos todos. Las siguientes pueden dejarlos en blanco o repetirlos, pero
     * si traen otro valor es un error: no se elige uno al azar.
     *
     * @param array<string, array> $existentes zapatillas que ya están en el catálogo
     * @return array<string, array{alta: array, tallas: array<string, array{talla: float, cantidad: int}>}>
     */
    private function agrupar(array $filas, array $existentes, array &$errores): array
    {
        $categorias = $this->porNombre($this->catalogo->categorias());
        $colores = $this->porNombre($this->catalogo->colores());

        $zapatillas = [];
        $primeraFila = [];
        foreach ($filas as $fila) {
            $codigo = $fila['codigo_marca'];
            $numero = $fila['linea'];

            if (!isset($zapatillas[$codigo])) {
                $zapatillas[$codigo] = ['alta' => [], 'tallas' => []];
                if (!isset($existentes[$codigo])) {
                    $primeraFila[$codigo] = $fila;
                    $zapatillas[$codigo]['alta'] = $this->validarAlta($fila['fila'], $numero, $categorias, $colores, $errores);
                }
            } elseif (isset($primeraFila[$codigo])) {
                foreach (self::COLUMNAS_ALTA as $campo) {
                    $valor = $fila['fila'][$campo];
                    if ($valor !== '' && mb_strtolower($valor) !== mb_strtolower($primeraFila[$codigo]['fila'][$campo])) {
                        $errores["fila.{$numero}.{$campo}"] = "No coincide con la fila {$primeraFila[$codigo]['linea']} de la misma zapatilla";
                    }
                }
            }

            $clave = GestionProductoServicio::claveTalla($fila['talla']);
            $zapatillas[$codigo]['tallas'][$clave] ??= ['talla' => $fila['talla'], 'cantidad' => 0];
            $zapatillas[$codigo]['tallas'][$clave]['cantidad'] += $fila['cantidad'];
        }

        foreach ($zapatillas as &$zapatilla) {
            ksort($zapatilla['tallas'], SORT_NUMERIC);   // las tallas se crean en orden
        }
        unset($zapatilla);   // la referencia seguiría apuntando al último elemento
        return $zapatillas;
    }

    /**
     * Nombre, colorway, categoría (por nombre), color (por nombre) y precio de
     * una zapatilla que todavía no está en el catálogo.
     *
     * @return array{nombre: string, colorway: string, categoria_id: int, color_id: int, precio: float}|array{}
     */
    private function validarAlta(array $fila, int $numero, array $categorias, array $colores, array &$errores): array
    {
        $erroresFila = [];
        $datos = [];
        try {
            $datos = (new Validador($fila))
                ->texto('nombre', GestionProductoServicio::NOMBRE_MAXIMO)
                ->texto('colorway', GestionProductoServicio::COLORWAY_MAXIMO)
                ->importe('precio', GestionProductoServicio::PRECIO_MINIMO, GestionProductoServicio::PRECIO_MAXIMO)
                ->validar();
        } catch (HttpExcepcion $error) {
            $erroresFila = $error->detalles['errores'] ?? [];
        }

        // Categoría y color por su nombre (sin distinguir mayúsculas): el proveedor no conoce nuestros ids
        foreach (['categoria' => $categorias, 'color' => $colores] as $campo => $valores) {
            $nombre = mb_strtolower($fila[$campo]);
            if ($nombre === '') {
                $erroresFila[$campo] = 'Obligatorio en una zapatilla nueva';
            } elseif (!isset($valores[$nombre])) {
                $erroresFila[$campo] = 'No existe. Valores: ' . implode(', ', array_column($valores, 'nombre'));
            } else {
                $datos["{$campo}_id"] = $valores[$nombre]['id'];
            }
        }

        foreach ($erroresFila as $campo => $motivo) {
            // "Obligatorio" a secas no explica por qué en otras filas no lo es
            $errores["fila.{$numero}.{$campo}"] = $motivo === 'Obligatorio' ? 'Obligatorio en una zapatilla nueva' : $motivo;
        }
        return $erroresFila === [] ? $datos : [];
    }

    /** @return array<string, array{id: int, nombre: string}> indexadas por nombre en minúsculas */
    private function porNombre(array $filas): array
    {
        $indice = [];
        foreach ($filas as $fila) {
            $indice[mb_strtolower($fila['nombre'])] = ['id' => (int) $fila['id'], 'nombre' => $fila['nombre']];
        }
        return $indice;
    }

    /**
     * ?referencia=ALB-2026-0042 (opcional). Si no llega, se genera con la fecha y
     * la hora para que los movimientos de stock digan de qué carga vienen.
     *
     * @throws HttpExcepcion 400
     */
    private function referencia(mixed $referencia): string
    {
        if ($referencia === null || $referencia === '') {
            return 'ALB-' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Ymd-His');
        }
        if (!is_string($referencia) || !preg_match(self::PATRON_REFERENCIA, $referencia)) {
            throw HttpExcepcion::peticionIncorrecta('Referencia no válida', [
                'referencia' => 'Hasta 50 caracteres: letras, números, / _ y -',
            ]);
        }
        return $referencia;
    }
}
