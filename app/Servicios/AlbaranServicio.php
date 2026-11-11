<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AlbaranRepositorio;
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
 * Así nunca queda medio albarán cargado: un archivo rechazado no ha sumado
 * nada, y la administración puede corregirlo y volver a enviarlo con la misma
 * referencia (todavía no cuenta como cargada).
 *
 * Una referencia que YA se cargó se rechaza con un 409 (y el intento queda en
 * la auditoría como aviso para la administración), porque volver a cargarla
 * duplicaría las unidades. Solo entra si la petición lo acepta expresamente
 * con ?aceptar_repetida=1&motivo=..., y esa carga queda marcada como repetida.
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
    private const MOTIVO_MINIMO = 5;
    private const MOTIVO_MAXIMO = 300;

    public function __construct(
        private readonly CatalogoInternoRepositorio $catalogo = new CatalogoInternoRepositorio(),
        private readonly StockRepositorio $stock = new StockRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
        private readonly GestionProductoServicio $productos = new GestionProductoServicio(),
        private readonly AlbaranRepositorio $albaranes = new AlbaranRepositorio(),
    ) {
    }

    // =========================================================================
    // Consulta de los albaranes ya cargados (almacén y administración)
    // =========================================================================

    /** GET /api/gestion/albaranes · cargas aceptadas, de la más reciente a la más antigua */
    public function listar(Paginacion $paginacion): array
    {
        $total = $this->albaranes->contar();
        $filas = $this->albaranes->listar($paginacion->porPagina, $paginacion->offset());

        return [
            'datos'      => array_map(self::resumenGuardado(...), $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /**
     * GET /api/gestion/albaranes/detalle?referencia= · resumen de la carga y las
     * tallas que entraron. La referencia va en la consulta y no en la ruta
     * porque puede llevar «/» (ALB/2026/42), que la ruta tomaría por otro tramo.
     *
     * @throws HttpExcepcion 400 referencia ausente o mal formada · 404 no se ha cargado
     */
    public function ver(mixed $referencia): array
    {
        if ($referencia === null || $referencia === '') {
            throw HttpExcepcion::peticionIncorrecta('Falta la referencia', ['referencia' => 'Obligatoria, ej. ?referencia=ALB-2026-0042']);
        }
        $referencia = $this->referencia($referencia);

        $carga = $this->albaranes->buscarPorReferencia($referencia)
            ?? throw HttpExcepcion::noEncontrado("No se ha cargado ningún albarán con la referencia {$referencia}");

        return self::resumenGuardado($carga) + [
            // Si la misma referencia se cargó más de una vez, esto es la última carga
            'cargas' => (int) $carga['cargas'],
            'tallas' => array_map(static fn (array $t): array => [
                'variante_id'      => (int) $t['variante_id'],
                'producto'         => [
                    'id'           => (int) $t['producto_id'],
                    'codigo_marca' => $t['codigo_marca'],
                    'nombre'       => $t['nombre'],
                    'colorway'     => $t['colorway'],
                ],
                'talla'            => (float) $t['talla'],
                'cantidad'         => (int) $t['cantidad'],
                'stock_resultante' => (int) $t['stock_resultante'],
            ], $this->albaranes->tallas((int) $carga['id'])),
        ];
    }

    /**
     * Una carga tal como la devuelve la API: las mismas claves que el resumen de
     * POST /api/gestion/albaranes, más quién y cuándo. El resumen sale del JSON
     * que se guardó en la auditoría al cargarlo.
     */
    private static function resumenGuardado(array $fila): array
    {
        $detalle = json_decode((string) $fila['detalle'], true, flags: JSON_THROW_ON_ERROR);

        return [
            'id'                     => (int) $fila['id'],
            'referencia'             => (string) ($detalle['referencia'] ?? ''),
            'fecha'                  => (new \DateTimeImmutable($fila['fecha']))->format(DATE_ATOM),
            // El usuario puede haberse borrado (la auditoría queda con usuario_id NULL)
            'usuario'                => $fila['usuario_id'] === null ? null : [
                'id'     => (int) $fila['usuario_id'],
                'nombre' => trim("{$fila['usuario_nombre']} {$fila['usuario_apellidos']}"),
                'email'  => $fila['usuario_email'],
            ],
            'filas'                  => (int) ($detalle['filas'] ?? 0),
            'unidades'               => (int) ($detalle['unidades'] ?? 0),
            'productos_nuevos'       => $detalle['productos_nuevos'] ?? [],
            'productos_actualizados' => $detalle['productos_actualizados'] ?? [],
            'tallas_nuevas'          => (int) ($detalle['tallas_nuevas'] ?? 0),
            'desactivados'           => $detalle['desactivados'] ?? [],
            // Las cargas anteriores a estas claves no las tienen en su JSON
            'tallas_desactivadas'    => $detalle['tallas_desactivadas'] ?? [],
            'repetida'               => (bool) ($detalle['repetida'] ?? false),
            'motivo_repetida'        => $detalle['motivo_repetida'] ?? null,
        ];
    }

    /**
     * GET /api/gestion/albaranes/avisos · intentos rechazados de cargar una
     * referencia que ya estaba cargada, del más reciente al más antiguo. Solo
     * administración: es quien decide si se carga igualmente.
     */
    public function avisos(Paginacion $paginacion): array
    {
        $total = $this->albaranes->contarAvisos();
        $filas = $this->albaranes->avisos($paginacion->porPagina, $paginacion->offset());

        return [
            'datos' => array_map(static function (array $fila): array {
                $detalle = json_decode((string) $fila['detalle'], true, flags: JSON_THROW_ON_ERROR);
                return [
                    'id'               => (int) $fila['id'],
                    'referencia'       => (string) ($detalle['referencia'] ?? ''),
                    'fecha'            => (new \DateTimeImmutable($fila['fecha']))->format(DATE_ATOM),
                    'usuario'          => $fila['usuario_id'] === null ? null : [
                        'id'     => (int) $fila['usuario_id'],
                        'nombre' => trim("{$fila['usuario_nombre']} {$fila['usuario_apellidos']}"),
                        'email'  => $fila['usuario_email'],
                    ],
                    'filas'            => (int) ($detalle['filas'] ?? 0),
                    'unidades'         => (int) ($detalle['unidades'] ?? 0),
                    'aceptada_despues' => (bool) $fila['aceptada_despues'],
                ];
            }, $filas),
            'paginacion' => $paginacion->meta($total),
        ];
    }

    /**
     * @param mixed $referencia número del albarán del proveedor (ej. ALB-2026-0042), de ?referencia=
     * @param mixed $aceptarRepetida ?aceptar_repetida=1 para cargar una referencia que ya se cargó
     * @param mixed $motivo ?motivo= obligatorio con aceptar_repetida: por qué se vuelve a cargar
     * @throws HttpExcepcion 400 archivo, filas o aceptación no válidos ·
     *                       409 referencia ya cargada sin aceptarla o zapatilla nueva dada de alta mientras tanto
     */
    public function cargar(array $usuario, string $csv, mixed $referencia, mixed $aceptarRepetida, mixed $motivo, ?string $ip): array
    {
        $referencia = $this->referencia($referencia);
        // null si no se acepta repetir; si se acepta, el motivo ya validado
        $motivoRepetida = $this->aceptacion($aceptarRepetida, $motivo);
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
            $resultado = BaseDatos::transaccion(function () use ($zapatillas, $marcaId, $usuario, $ip, $referencia, $filas, $motivoRepetida): array {
                // La comprobación de «ya cargada» y la escritura van bajo el mismo cerrojo:
                // dos envíos simultáneos de la referencia no pueden pasar los dos
                $this->albaranes->bloquearReferencia($referencia);
                $anterior = $this->albaranes->buscarPorReferencia($referencia);
                if ($anterior !== null && $motivoRepetida === null) {
                    // No se escribe nada: el rechazo se audita fuera, después del COMMIT
                    return ['anterior' => $anterior];
                }
                $repetida = $anterior === null ? null : ['motivo' => $motivoRepetida, 'anterior_id' => (int) $anterior['id']];
                return ['resumen' => $this->escribir($zapatillas, $marcaId, $usuario['id'], $ip, $referencia, count($filas), $repetida)];
            });
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

        if (isset($resultado['anterior'])) {
            $this->rechazarRepetida($usuario, $referencia, $resultado['anterior'], $filas, $ip);
        }
        $resumen = $resultado['resumen'];

        Log::info('Albarán cargado', ['referencia' => $referencia, 'unidades' => $resumen['unidades'], 'repetida' => $resumen['repetida']]);

        $avisos = [];
        if ($resumen['desactivados'] !== []) {
            $avisos[] = 'Se ha sumado el stock de zapatillas desactivadas, pero siguen sin venderse. '
                . 'Para volver a venderlas: PATCH /api/gestion/productos/{id} con {"activo": true}';
        }
        if ($resumen['tallas_desactivadas'] !== []) {
            $avisos[] = 'Se ha sumado el stock de tallas desactivadas (ver tallas_desactivadas): siguen sin venderse hasta que se reactiven';
        }
        if ($avisos !== []) {
            $resumen['aviso'] = implode('. ', $avisos);
        }
        return $resumen;
    }

    /**
     * La referencia ya estaba cargada y la petición no lo acepta: el intento se
     * deja en la auditoría (los avisos que ve la administración en el panel) y
     * se responde 409 con los datos de la carga anterior.
     *
     * Se registra fuera de la transacción de la carga, que ya ha terminado sin
     * escribir nada: si se registrara dentro, el 409 lo desharía con el ROLLBACK.
     *
     * @throws HttpExcepcion 409 siempre
     */
    private function rechazarRepetida(array $usuario, string $referencia, array $anterior, array $filas, ?string $ip): never
    {
        $previa = self::resumenGuardado($anterior);
        $unidades = array_sum(array_column($filas, 'cantidad'));

        $this->auditoria->registrar($usuario['id'], AlbaranRepositorio::EVENTO_RECHAZO, 'albaran', null, 'denegado', [
            'referencia'  => $referencia,
            'filas'       => count($filas),
            'unidades'    => $unidades,
            'anterior_id' => $previa['id'],
        ], $ip);
        Log::info('Albarán con referencia repetida rechazado', ['referencia' => $referencia]);

        throw HttpExcepcion::conflicto(
            "La referencia {$referencia} ya se cargó: no se ha sumado ninguna unidad",
            [
                'referencia'     => $referencia,
                'cargas'         => (int) $anterior['cargas'],
                'carga_anterior' => [
                    'fecha'    => $previa['fecha'],
                    'usuario'  => $previa['usuario'],
                    'filas'    => $previa['filas'],
                    'unidades' => $previa['unidades'],
                ],
                'aceptar'        => 'Para cargarlo igualmente, repite la petición con ?aceptar_repetida=1&motivo=<por qué se vuelve a cargar>',
            ]
        );
    }

    /**
     * ?aceptar_repetida=1&motivo=... Cargar otra vez una referencia duplica sus
     * unidades, así que solo se hace si se pide expresamente y diciendo por qué
     * (el motivo queda en la auditoría junto a la carga).
     *
     * @return string|null el motivo si se acepta repetir; null si no
     * @throws HttpExcepcion 400 valor de aceptar_repetida no válido o motivo ausente
     */
    private function aceptacion(mixed $aceptar, mixed $motivo): ?string
    {
        if ($aceptar === null || $aceptar === '' || $aceptar === '0' || $aceptar === 'false') {
            return null;
        }
        if ($aceptar !== '1' && $aceptar !== 'true') {
            throw HttpExcepcion::peticionIncorrecta('Parámetro aceptar_repetida no válido', [
                'aceptar_repetida' => 'Usa 1 para cargar una referencia que ya se cargó',
            ]);
        }

        $motivo = is_string($motivo) ? trim($motivo) : '';
        $largo = mb_strlen($motivo);
        if ($largo < self::MOTIVO_MINIMO || $largo > self::MOTIVO_MAXIMO) {
            throw HttpExcepcion::peticionIncorrecta('Falta el motivo para cargar una referencia repetida', [
                'motivo' => $largo === 0
                    ? 'Obligatorio al aceptar una referencia repetida: explica por qué se vuelve a cargar'
                    : 'Entre ' . self::MOTIVO_MINIMO . ' y ' . self::MOTIVO_MAXIMO . ' caracteres',
            ]);
        }
        return $motivo;
    }

    /**
     * Da de alta lo que falta y suma las unidades. Se llama dentro de la transacción.
     *
     * @param array<string, array> $zapatillas agrupadas por código (ver agrupar())
     * @param array{motivo: string, anterior_id: int}|null $repetida si se aceptó repetir la referencia
     */
    private function escribir(array $zapatillas, int $marcaId, int $usuarioId, ?string $ip, string $referencia, int $filas, ?array $repetida): array
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
            'tallas_desactivadas'    => [],
            'repetida'               => $repetida !== null,
            'motivo_repetida'        => $repetida['motivo'] ?? null,
        ];
        // Unidades que entran en cada talla: variante_id => cantidad, código y talla
        $entradas = [];

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

            $resumen['tallas_nuevas'] += $this->prepararTallas($productoId, $codigo, $variantes, $zapatilla['tallas'], $entradas);
            $resumen['unidades'] += array_sum(array_column($zapatilla['tallas'], 'cantidad'));
        }

        $resumen['tallas_desactivadas'] = $this->darEntrada($entradas, $usuarioId, "Albarán {$referencia}");

        $detalle = $resumen + ($repetida === null ? [] : ['anterior_id' => $repetida['anterior_id']]);
        $this->auditoria->registrar($usuarioId, AlbaranRepositorio::EVENTO_CARGA, 'albaran', null, 'exito', $detalle, $ip);
        return $resumen;
    }

    /**
     * Crea las tallas que el producto no tenía (con stock 0 y su RFID) y anota
     * en $entradas cuántas unidades recibe cada talla. Todavía no mueve stock.
     *
     * @param array<string, int> $variantes "42.5" => id de la talla
     * @param array<int, array{cantidad: int, codigo_marca: string, talla: float}> $entradas
     * @return int tallas creadas
     */
    private function prepararTallas(int $productoId, string $codigo, array $variantes, array $tallas, array &$entradas): int
    {
        $creadas = 0;
        foreach ($tallas as $clave => $linea) {
            if (!isset($variantes[$clave])) {
                $variantes[$clave] = $this->catalogo->crearTalla($productoId, $linea['talla']);
                $creadas++;
            }
            $entradas[$variantes[$clave]] = ['cantidad' => $linea['cantidad'], 'codigo_marca' => $codigo, 'talla' => $linea['talla']];
        }
        return $creadas;
    }

    /**
     * Suma las unidades talla a talla. Antes se bloquean TODAS las tallas en
     * orden de id, como hacen los pedidos y las cancelaciones: si el albarán las
     * fuera bloqueando en el orden del CSV, podría esperar a un pedido que a su
     * vez espera a una talla que ya tiene el albarán (interbloqueo).
     *
     * @param array<int, array{cantidad: int, codigo_marca: string, talla: float}> $entradas por id de talla
     * @return list<array{codigo_marca: string, talla: float}> tallas desactivadas que han recibido stock
     */
    private function darEntrada(array $entradas, int $usuarioId, string $motivo): array
    {
        $bloqueadas = $this->stock->bloquearVariantes(array_keys($entradas));
        ksort($entradas);

        $desactivadas = [];
        foreach ($entradas as $varianteId => $entrada) {
            // La talla puede estar desactivada aunque la zapatilla esté a la venta: las
            // unidades existen y se cuentan, pero no se venden hasta que se reactive
            if (isset($bloqueadas[$varianteId]) && !$bloqueadas[$varianteId]['activo']) {
                $desactivadas[] = ['codigo_marca' => $entrada['codigo_marca'], 'talla' => $entrada['talla']];
            }
            // Siempre por mover(): cada entrada deja su movimiento con la referencia del albarán
            $this->stock->mover($varianteId, $entrada['cantidad'], 'entrada', null, $usuarioId, $motivo);
        }
        usort($desactivadas, static fn (array $a, array $b): int => [$a['codigo_marca'], $a['talla']] <=> [$b['codigo_marca'], $b['talla']]);
        return $desactivadas;
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
        $lineas = preg_split('/\r\n|\n|\r/', self::textoUtf8($csv));
        $separador = self::separador($lineas[0]);
        $columnas = self::columnas($lineas[0], $separador);
        return $this->leerFilas(array_slice($lineas, 1, preserve_keys: true), $columnas, $separador);
    }

    /**
     * El archivo como texto UTF-8 sin BOM. El panel ya avisa antes de enviar un
     * CSV que no es UTF-8, pero la API también la usan otros clientes.
     *
     * @throws HttpExcepcion 400 si no es UTF-8
     */
    private static function textoUtf8(string $csv): string
    {
        // El BOM lo añade Excel al guardar en "CSV UTF-8": no forma parte de la primera columna
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw HttpExcepcion::peticionIncorrecta('El archivo debe estar en UTF-8', [
                'archivo' => 'Guárdalo como «CSV UTF-8 (delimitado por comas)» en Excel',
            ]);
        }
        return $csv;
    }

    /** Excel en español guarda los CSV con punto y coma (la coma es el separador decimal) */
    private static function separador(string $cabecera): string
    {
        return substr_count($cabecera, ';') > substr_count($cabecera, ',') ? ';' : ',';
    }

    /**
     * Nombres de las columnas de la cabecera, en minúsculas y en su orden.
     *
     * @return list<string>
     * @throws HttpExcepcion 400 si falta alguna columna o hay alguna repetida
     */
    private static function columnas(string $cabecera, string $separador): array
    {
        $columnas = array_map(static fn (?string $c): string => strtolower(trim((string) $c)), str_getcsv($cabecera, $separador, '"', ''));

        $faltan = array_diff(self::COLUMNAS, $columnas);
        if ($faltan !== [] || count(array_unique($columnas)) !== count($columnas)) {
            throw HttpExcepcion::peticionIncorrecta('La cabecera del albarán no es válida', [
                'cabecera' => 'Debe ser: ' . implode(',', self::COLUMNAS)
                    . ($faltan === [] ? ' (sin columnas repetidas)' : '. Faltan: ' . implode(', ', $faltan)),
            ]);
        }
        return $columnas;
    }

    /**
     * Las filas de datos, cada una validada por separado.
     *
     * @param array<int, string> $lineas líneas tras la cabecera, con su índice del archivo
     * @return array{0: list<array>, 1: array<string, string>} filas y errores
     * @throws HttpExcepcion 400 si no hay filas o hay demasiadas
     */
    private function leerFilas(array $lineas, array $columnas, string $separador): array
    {
        $filas = [];
        $errores = [];
        $leidas = 0;
        foreach ($lineas as $indice => $linea) {
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
