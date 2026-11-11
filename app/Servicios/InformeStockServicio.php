<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Csv;
use App\Nucleo\Formato;
use App\Nucleo\HttpExcepcion;
use App\Nucleo\Log;
use App\Nucleo\Paginacion;
use App\Nucleo\Validador;
use App\Repositorios\AuditoriaRepositorio;
use App\Repositorios\InformeStockRepositorio;
use App\Seguridad\Permisos;

/**
 * Informes de stock que el almacén genera y envía a administración:
 *   - stock_bajo: tallas en su mínimo o por debajo (el umbral de siempre,
 *     variantes.stock_minimo de cada talla).
 *   - sin_ventas: zapatillas a la venta que no se venden desde hace N días
 *     (1 a 365, los elige quien lo genera) o que no se han vendido nunca.
 *
 * El almacén ve la vista previa, la descarga en CSV y la envía con un
 * comentario. Al enviarla se vuelven a calcular las filas en el servidor (no
 * se fía de lo que traiga la petición) y se guarda esa FOTO: lo que reciba
 * administración es lo que había al pulsar «Enviar», aunque el stock cambie
 * después. Administración los ve todos y los marca como revisados; el almacén
 * solo ve los suyos.
 *
 * Dónde se guardan: en la tabla auditoria (ver InformeStockRepositorio).
 */
final class InformeStockServicio
{
    public const TIPOS = ['stock_bajo', 'sin_ventas'];
    public const ESTADOS = ['pendiente', 'revisado'];

    /** Días que se pueden pedir en «sin ventas»: de un día a un año */
    public const DIAS_MINIMO = 1;
    public const DIAS_MAXIMO = 365;

    /**
     * Filas como máximo en una foto. Con el catálogo actual son unas decenas;
     * el límite evita que un catálogo enorme meta un detalle de varios MB en
     * cada fila de la auditoría (JSONB lo admitiría, pero la auditoría se lee
     * entera en su listado). Si se supera, se guardan las primeras (las más
     * urgentes, por el orden de la consulta), recortado = true y total_filas
     * dice cuántas había de verdad.
     */
    public const MAX_FILAS_FOTO = 1000;

    private const COMENTARIO_MAXIMO = 500;
    private const NO_ENCONTRADO = 'Informe de stock no encontrado';

    /** Columnas del CSV de cada tipo, en orden */
    private const COLUMNAS_CSV = [
        'stock_bajo' => ['codigo_marca', 'modelo', 'colorway', 'talla', 'stock', 'stock_minimo', 'rfid_tienda'],
        'sin_ventas' => ['codigo_marca', 'modelo', 'colorway', 'tallas', 'stock_total', 'ultima_venta', 'dias_sin_vender'],
    ];


    public function __construct(
        private readonly InformeStockRepositorio $informes = new InformeStockRepositorio(),
        private readonly AuditoriaRepositorio $auditoria = new AuditoriaRepositorio(),
    ) {
    }

    /**
     * Calcula el informe sin guardarlo.
     *
     * @throws HttpExcepcion 400 tipo o días no válidos
     */
    public function vistaPrevia(array $query): array
    {
        return $this->calcular(self::parametros($query));
    }

    /**
     * Guarda la foto del informe y lo deja pendiente de revisión.
     *
     * @throws HttpExcepcion 400 tipo, días o comentario no válidos
     */
    public function enviar(array $usuario, array $entrada, ?string $ip): array
    {
        $parametros = self::parametros($entrada);
        $comentario = (new Validador($entrada))->texto('comentario', self::COMENTARIO_MAXIMO, obligatorio: false)
            ->validar()['comentario'] ?? null;

        $informe = $this->calcular($parametros);
        $guardado = $this->informes->guardarEnvio($usuario['id'], [
            'tipo'        => $informe['tipo'],
            'parametros'  => $informe['parametros'],
            'comentario'  => $comentario,
            'generado_en' => $informe['generado_en'],
            'total_filas' => $informe['total_filas'],
            'recortado'   => $informe['recortado'],
            'filas'       => $informe['filas'],
        ], $ip);

        Log::info('Informe de stock enviado', [
            'informe_id' => $guardado['id'], 'tipo' => $informe['tipo'], 'filas' => $informe['total_filas'],
        ]);

        return $this->ver($usuario, (string) $guardado['id']);
    }

    /**
     * Listado: ?estado=pendiente|revisado&tipo=stock_bajo|sin_ventas&pagina=1.
     * Quien no revisa (el almacén) solo ve los que ha enviado él.
     */
    public function listar(array $usuario, array $query, Paginacion $paginacion): array
    {
        $filtros = [];
        $errores = [];
        foreach (['estado' => self::ESTADOS, 'tipo' => self::TIPOS] as $campo => $valores) {
            $valor = $query[$campo] ?? '';
            if ($valor === '') {
                continue;
            }
            if (is_string($valor) && in_array($valor, $valores, true)) {
                $filtros[$campo] = $valor;
            } else {
                $errores[$campo] = 'Valores: ' . implode(', ', $valores);
            }
        }
        if ($errores !== []) {
            throw HttpExcepcion::peticionIncorrecta('Filtros no válidos', $errores);
        }
        if (!self::revisa($usuario)) {
            $filtros['creado_por'] = $usuario['id'];
        }

        $total = $this->informes->contar($filtros);
        $filas = $this->informes->listar($filtros, $paginacion->porPagina, $paginacion->offset());

        $respuesta = [
            'datos'      => array_map(fn (array $f): array => $this->formatear($f, false), $filas),
            'paginacion' => $paginacion->meta($total),
        ];
        // El contador de la barra lateral del panel: lo que espera a administración
        if (self::revisa($usuario)) {
            $respuesta['pendientes'] = $this->informes->contar(['estado' => 'pendiente']);
        }
        return $respuesta;
    }

    /**
     * Un informe con su foto. El de otro usuario, para quien no revisa, es un
     * 404 como si no existiera (igual que los pedidos de otro cliente).
     *
     * @throws HttpExcepcion 404
     */
    public function ver(array $usuario, string $id): array
    {
        return $this->formatear($this->buscarVisible($usuario, Validador::idRuta($id, self::NO_ENCONTRADO, Validador::ID_MAXIMO_BIGINT)), true);
    }

    /**
     * Lo da por revisado. Si ya lo estaba no se escribe nada y se devuelve
     * como está (PATCH idempotente, como el estado de una consulta).
     *
     * @throws HttpExcepcion 404
     */
    public function marcarRevisado(array $usuario, string $id, ?string $ip): array
    {
        $idInforme = Validador::idRuta($id, self::NO_ENCONTRADO, Validador::ID_MAXIMO_BIGINT);

        BaseDatos::transaccion(function () use ($idInforme, $usuario, $ip): void {
            // Bloquea el envío: dos revisiones a la vez se ponen en fila
            $informe = $this->informes->buscar($idInforme, bloquear: true)
                ?? throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
            if ($this->informes->revision($idInforme) !== null) {
                return;
            }
            $this->auditoria->registrar(
                $usuario['id'], InformeStockRepositorio::EVENTO_REVISAR, InformeStockRepositorio::ENTIDAD, $idInforme, 'exito',
                ['tipo' => $informe['tipo'], 'enviado_por' => $informe['creado_por_id'] === null ? null : (int) $informe['creado_por_id']],
                $ip
            );
        });

        return $this->ver($usuario, (string) $idInforme);
    }

    /**
     * CSV de un informe enviado (su foto, no el stock de ahora).
     *
     * @return array{contenido: string, tipo: string, nombre_archivo: string}
     */
    public function csvGuardado(array $usuario, string $id): array
    {
        $informe = $this->ver($usuario, $id);
        $nombre = 'kalma-informe-stock-' . $informe['id'] . '-' . str_replace('_', '-', $informe['tipo']) . '.csv';
        return $this->archivoCsv($informe['tipo'], $informe['filas'], $nombre);
    }

    /** CSV de la vista previa (lo de ahora, sin guardar) */
    public function csvVistaPrevia(array $query): array
    {
        $informe = $this->vistaPrevia($query);
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Ymd');
        $sufijo = $informe['tipo'] === 'sin_ventas' ? '-' . $informe['parametros']['dias'] . 'dias' : '';
        $nombre = 'kalma-' . str_replace('_', '-', $informe['tipo']) . $sufijo . '-' . $hoy . '.csv';
        return $this->archivoCsv($informe['tipo'], $informe['filas'], $nombre);
    }

    // =========================================================================

    /**
     * tipo obligatorio y, en sin_ventas, dias entre 1 y 365. En stock_bajo los
     * días no se usan y se ignoran.
     *
     * @return array{tipo: string, dias: ?int}
     * @throws HttpExcepcion 400 con todos los errores juntos
     */
    private static function parametros(array $entrada): array
    {
        $validador = (new Validador($entrada))->opcion('tipo', self::TIPOS);
        if (($entrada['tipo'] ?? null) === 'sin_ventas') {
            $validador->entero('dias', self::DIAS_MINIMO, self::DIAS_MAXIMO);
        }
        $datos = $validador->validar();
        return ['tipo' => $datos['tipo'], 'dias' => $datos['dias'] ?? null];
    }

    /** Las filas del informe ya con formato, recortadas a MAX_FILAS_FOTO */
    private function calcular(array $parametros): array
    {
        // Una fila más del máximo: si llega, es que hay que recortar
        $limite = self::MAX_FILAS_FOTO + 1;

        if ($parametros['tipo'] === 'stock_bajo') {
            $filas = array_map(self::filaStockBajo(...), $this->informes->stockBajo($limite));
            $guardados = ['umbral' => 'stock_minimo'];
            $total = count($filas) > self::MAX_FILAS_FOTO ? $this->informes->contarStockBajo() : count($filas);
        } else {
            $dias = $parametros['dias'];
            $filas = array_map(self::filaSinVentas(...), $this->informes->sinVentas($dias, $limite));
            $corte = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->modify("-{$dias} days");
            $guardados = ['dias' => $dias, 'sin_ventas_desde' => $corte->format('Y-m-d')];
            $total = count($filas) > self::MAX_FILAS_FOTO ? $this->informes->contarSinVentas($dias) : count($filas);
        }

        return [
            'tipo'        => $parametros['tipo'],
            'parametros'  => $guardados,
            'generado_en' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format(DATE_ATOM),
            'total_filas' => $total,
            'recortado'   => count($filas) > self::MAX_FILAS_FOTO,
            'filas'       => array_slice($filas, 0, self::MAX_FILAS_FOTO),
        ];
    }

    private static function filaStockBajo(array $f): array
    {
        return [
            'variante_id'  => (int) $f['variante_id'],
            'producto_id'  => (int) $f['producto_id'],
            'nombre'       => $f['nombre'],
            'colorway'     => $f['colorway'],
            'codigo_marca' => $f['codigo_marca'],
            'talla'        => (float) $f['talla'],
            'stock'        => (int) $f['stock'],
            'stock_minimo' => (int) $f['stock_minimo'],
            'rfid_tienda'  => $f['rfid_tienda'],
        ];
    }

    private static function filaSinVentas(array $f): array
    {
        return [
            'producto_id'     => (int) $f['producto_id'],
            'nombre'          => $f['nombre'],
            'colorway'        => $f['colorway'],
            'codigo_marca'    => $f['codigo_marca'],
            'stock_total'     => (int) $f['stock_total'],
            'tallas'          => (int) $f['tallas'],
            'ultima_venta'    => Formato::fecha($f['ultima_venta']),
            'dias_sin_vender' => $f['dias_sin_vender'] === null ? null : (int) $f['dias_sin_vender'],
        ];
    }

    /** @throws HttpExcepcion 404 */
    private function buscarVisible(array $usuario, int $id): array
    {
        $informe = $this->informes->buscar($id);
        if ($informe === null || (!self::revisa($usuario) && (int) $informe['creado_por_id'] !== $usuario['id'])) {
            throw HttpExcepcion::noEncontrado(self::NO_ENCONTRADO);
        }
        return $informe;
    }

    private static function revisa(array $usuario): bool
    {
        return Permisos::tiene($usuario['rol'], 'informes_stock.revisar');
    }


    private function formatear(array $f, bool $conFilas): array
    {
        $persona = static fn (int|string|null $id, ?string $nombre, ?string $apellidos, ?string $email): ?array => $id === null ? null : [
            'id'     => (int) $id,
            'nombre' => trim(($nombre ?? '') . ' ' . ($apellidos ?? '')),
            'email'  => $email,
        ];

        $informe = [
            'id'          => (int) $f['id'],
            'tipo'        => $f['tipo'],
            'estado'      => $f['revisado_en'] === null ? 'pendiente' : 'revisado',
            'parametros'  => json_decode($f['parametros'] ?? '{}', true),
            'comentario'  => $f['comentario'],
            'total_filas' => (int) $f['total_filas'],
            'recortado'   => (bool) $f['recortado'],
            'creado_en'   => Formato::fecha($f['creado_en']),
            'creado_por'  => $persona($f['creado_por_id'], $f['creado_por_nombre'], $f['creado_por_apellidos'], $f['creado_por_email']),
            'revisado_en' => Formato::fecha($f['revisado_en']),
            'revisado_por' => $persona($f['revisado_por_id'], $f['revisado_por_nombre'], $f['revisado_por_apellidos'], $f['revisado_por_email']),
        ];
        if ($conFilas) {
            $informe['filas'] = json_decode($f['filas'] ?? '[]', true);
        }
        return $informe;
    }

    // --- CSV ---------------------------------------------------------------------

    /**
     * CSV para Excel en español, con las mismas reglas que la exportación de
     * pedidos (ver Csv): BOM, «;», coma decimal en las tallas (42,5) y celdas
     * de texto protegidas contra la inyección de fórmulas.
     *
     * @return array{contenido: string, tipo: string, nombre_archivo: string}
     */
    private function archivoCsv(string $tipo, array $filas, string $nombreArchivo): array
    {
        $celdas = array_map(static fn (array $f): array => $tipo === 'stock_bajo'
            ? [
                Csv::texto($f['codigo_marca']), Csv::texto($f['nombre']), Csv::texto($f['colorway']),
                Csv::talla((float) $f['talla']), (string) (int) $f['stock'], (string) (int) $f['stock_minimo'],
                Csv::texto($f['rfid_tienda']),
            ]
            : [
                Csv::texto($f['codigo_marca']), Csv::texto($f['nombre']), Csv::texto($f['colorway']),
                (string) (int) $f['tallas'], (string) (int) $f['stock_total'],
                // Fecha y hora de España sin zona, que Excel reconoce como fecha; vacía si nunca se vendió
                $f['ultima_venta'] === null ? '' : (new \DateTimeImmutable($f['ultima_venta']))
                    ->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i'),
                $f['dias_sin_vender'] === null ? '' : (string) (int) $f['dias_sin_vender'],
            ], $filas);

        return [
            'contenido'      => Csv::generar(self::COLUMNAS_CSV[$tipo], $celdas),
            'tipo'           => Csv::TIPO,
            'nombre_archivo' => $nombreArchivo,
        ];
    }
}
