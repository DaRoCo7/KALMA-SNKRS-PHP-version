/*
 * Sección «Informes» (permiso informes.ver): ventas por mes, ventas por
 * modelo y personalizaciones, cada una con su gráfica y su tabla, y la
 * exportación de los pedidos.
 *
 * API: GET /api/gestion/informes/{ventas-por-mes|ventas-por-modelo|personalizaciones}?desde&hasta
 * y GET /api/gestion/exportar/pedidos?formato=csv|json&estado&desde&hasta (datos.exportar).
 *
 * Un solo filtro de fechas, arriba, para todo lo de la página: las tres
 * gráficas y la exportación cuentan siempre el mismo periodo y sus cifras
 * cuadran entre sí. Vive en el hash (#informes?desde=2026-09-01&hasta=...).
 *
 * Por qué la exportación va aquí y no en «Pedidos»: admite los mismos
 * filtros de fechas que los informes (la lista de pedidos no filtra por
 * fecha) y es de administración, mientras que «Pedidos» también lo usa el
 * almacén, que no puede exportar.
 *
 * Las gráficas de ventas que comparte con el Resumen están en graficas.js.
 */

import { api, crear, precio } from '../api.js';
import { ESTADOS, NOMBRE_ESTADO, bloqueError, cabeceraSeccion, contar, horaActual, numero, sesionCaducada, textoError } from './comun.js';
import { PERIODOS, campoFiltro, desplegable, fechaDia, periodoDe } from './piezas.js';
import {
    barras, capitalizar, graficaIngresosMes, graficaModelos, linea, mesCorto, nombreMes,
} from './graficas.js';

const MODELOS_EN_GRAFICA = 10;

const NOMBRE_TIPO_OPCION = {
    packaging: 'Caja',
    bordado: 'Bordado',
    cordones: 'Cordones',
    certificado: 'Certificado',
};

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let control = null;

    const { cabecera, linea: lineaCabecera, acciones } = cabeceraSeccion('Informes');
    lineaCabecera.textContent = 'Ventas sin pedidos cancelados.';
    const sello = crear('span', 'seccion__sello');
    acciones.append(sello);

    // --- Filtro de periodo: una fila encima de todo lo que afecta ----------------
    const filtros = crear('form', 'barra-filtros');
    filtros.setAttribute('aria-label', 'Periodo de los informes');
    const rapidos = crear('div', 'barra-filtros__grupo');
    rapidos.setAttribute('role', 'group');
    rapidos.setAttribute('aria-label', 'Periodos rápidos');
    const botonesPeriodo = new Map();
    for (const [clave, texto, rango] of PERIODOS) {
        const boton = crear('button', 'control', texto);
        boton.type = 'button';
        boton.addEventListener('click', () => irCon(rango()));
        rapidos.append(boton);
        botonesPeriodo.set(clave, boton);
    }
    const desde = crear('input', 'campo-texto');
    desde.type = 'date';
    const hasta = crear('input', 'campo-texto');
    hasta.type = 'date';
    const aplicar = crear('button', 'control', 'Aplicar');
    aplicar.type = 'submit';
    const errorFechas = crear('p', 'barra-filtros__error');
    errorFechas.setAttribute('role', 'alert');
    filtros.append(rapidos, campoFiltro('Desde', desde), campoFiltro('Hasta', hasta), aplicar, errorFechas);
    filtros.addEventListener('submit', (evento) => {
        evento.preventDefault();
        if (desde.value && hasta.value && desde.value > hasta.value) {
            errorFechas.textContent = '«Desde» no puede ser posterior a «Hasta».';
            desde.setAttribute('aria-invalid', 'true');
            return;
        }
        irCon({ desde: desde.value, hasta: hasta.value });
    });

    const cuerpo = crear('div', 'analisis');
    contenedor.append(cabecera, filtros, cuerpo);

    function irCon(rango) {
        ctx.irA('informes', { ...params, ...rango }, { reemplazar: true });
    }

    function pintarFiltros() {
        desde.value = params.desde ?? '';
        hasta.value = params.hasta ?? '';
        desde.removeAttribute('aria-invalid');
        errorFechas.textContent = '';
        const elegido = periodoDe(params.desde ?? '', params.hasta ?? '');
        for (const [clave, boton] of botonesPeriodo) {
            boton.setAttribute('aria-pressed', String(clave === elegido));
        }
        lineaCabecera.textContent = `Ventas sin pedidos cancelados · ${textoPeriodo(params)}.`;
    }

    async function cargar() {
        control?.abort();
        control = new AbortController();
        const senal = control.signal;
        // Al volver a pedir, lo de antes se queda atenuado en su sitio: sin saltos
        cuerpo.setAttribute('aria-busy', 'true');
        const consulta = new URLSearchParams(Object.entries({ desde: params.desde ?? '', hasta: params.hasta ?? '' })
            .filter(([, v]) => v)).toString();
        const sufijo = consulta ? `?${consulta}` : '';

        try {
            const [porMes, porModelo, personalizaciones] = await Promise.all([
                api(`/api/gestion/informes/ventas-por-mes${sufijo}`, { senal }),
                api(`/api/gestion/informes/ventas-por-modelo${sufijo}`, { senal }),
                api(`/api/gestion/informes/personalizaciones${sufijo}`, { senal }),
            ]);
            pintar(porMes.datos.filas, porModelo.datos.filas, personalizaciones.datos.filas);
            sello.textContent = `Actualizado a las ${horaActual()}`;
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            if (error.estado === 400) {
                errorFechas.textContent = Object.values(error.detalles?.errores ?? {}).join(' ') || error.message;
                return;
            }
            cuerpo.replaceChildren(bloqueError(textoError(error), () => cargar()));
        } finally {
            if (!senal.aborted) {
                cuerpo.removeAttribute('aria-busy');
            }
        }
    }

    function pintar(porMes, porModelo, personalizaciones) {
        const meses = crear('div', 'analisis__pareja');
        meses.append(
            tarjeta(graficaIngresosMes(porMes, textoPeriodo(params))),
            tarjeta(graficaParesMes(porMes)),
        );
        const otros = crear('div', 'analisis__pareja');
        otros.append(
            tarjeta(graficaModelos(porModelo, MODELOS_EN_GRAFICA, textoPeriodo(params), true)),
            tarjeta(graficaPersonalizaciones(personalizaciones)),
        );
        cuerpo.replaceChildren(meses, otros);
        if (ctx.puede('datos.exportar')) {
            cuerpo.append(bloqueExportar(params));
        }
    }

    pintarFiltros();
    cargar();
    ctx.senal.addEventListener('abort', () => control?.abort());
    document.addEventListener('panel:cambio', () => cargar(), { signal: ctx.senal });

    return {
        actualizar(nuevos) {
            params = limpiar(nuevos);
            pintarFiltros();
            cargar();
        },
    };
}

/** Solo las fechas con forma AAAA-MM-DD; lo demás del hash se ignora */
function limpiar(entrada) {
    const fecha = (v) => (/^\d{4}-\d{2}-\d{2}$/.test(v ?? '') ? v : undefined);
    return { desde: fecha(entrada.desde), hasta: fecha(entrada.hasta) };
}

function textoPeriodo({ desde, hasta }) {
    if (desde && hasta) {
        return `del ${fechaDia(desde)} al ${fechaDia(hasta)}`;
    }
    if (desde) {
        return `desde el ${fechaDia(desde)}`;
    }
    if (hasta) {
        return `hasta el ${fechaDia(hasta)}`;
    }
    return 'desde la apertura';
}

function tarjeta(contenido) {
    const caja = crear('section', 'tarjeta');
    caja.append(contenido);
    return caja;
}

// =============================================================================
// Gráficas propias de esta sección (las que comparte con el Resumen están en graficas.js)
// =============================================================================

/** Pares vendidos por mes: otra medida, otra gráfica (nunca un segundo eje) */
function graficaParesMes(filas) {
    const ultimo = filas[filas.length - 1];
    const anterior = filas[filas.length - 2];
    let titulo = 'Sin pares vendidos en este periodo';
    if (ultimo && anterior) {
        const diferencia = ultimo.unidades - anterior.unidades;
        titulo = diferencia === 0
            ? `${capitalizar(nombreMes(ultimo.mes))} iguala al mes anterior: ${contar(ultimo.unidades, 'par', 'pares')}`
            : `${capitalizar(nombreMes(ultimo.mes))} ${diferencia > 0 ? 'sube' : 'baja'} a ${contar(ultimo.unidades, 'par', 'pares')} (${diferencia > 0 ? '+' : '−'}${numero(Math.abs(diferencia))} sobre ${MESES_CORTOS(anterior.mes)})`;
    } else if (ultimo) {
        titulo = `${contar(ultimo.unidades, 'par vendido', 'pares vendidos')} en ${nombreMes(ultimo.mes)}`;
    }
    return linea({
        titulo,
        subtitulo: 'Pares vendidos por mes',
        filas,
        valor: (f) => f.unidades,
        etiqueta: (f) => capitalizar(nombreMes(f.mes)),
        etiquetaEje: (f, i) => mesCorto(f.mes, i),
        formato: (n) => contar(n, 'par', 'pares'),
        formatoEje: (n) => numero(n),
        detalle: (f) => contar(f.pedidos, 'pedido', 'pedidos'),
        vacio: 'No hay ventas en este periodo.',
    });
}

const MESES_CORTOS = (aaaamm) => nombreMes(aaaamm).split(' ')[0];

/** Opciones de personalización por veces que se eligieron; el recargo, en el tooltip y la tabla */
function graficaPersonalizaciones(filas) {
    const ordenadas = [...filas].sort((a, b) => b.veces - a.veces || b.ingresos_recargo - a.ingresos_recargo);
    const primera = ordenadas[0];
    const recargos = filas.reduce((s, f) => s + f.ingresos_recargo, 0);
    return barras({
        titulo: primera
            ? `${primera.nombre}, la personalización más pedida: ${contar(primera.veces, 'vez', 'veces')}`
            : 'Ninguna personalización en este periodo',
        subtitulo: `Líneas de pedido que la eligieron · ${precio(recargos)} en recargos`,
        filas: ordenadas,
        valor: (f) => f.veces,
        etiqueta: (f) => f.nombre,
        sub: (f) => NOMBRE_TIPO_OPCION[f.tipo] ?? f.tipo,
        formato: (n) => contar(n, 'vez', 'veces'),
        detalle: (f) => `${contar(f.pares, 'par', 'pares')} · ${precio(f.ingresos_recargo)} de recargo`,
        destacar: (f, i) => i === 0,
        columnasTabla: [
            { titulo: 'Opción', valor: (f) => f.nombre },
            { titulo: 'Tipo', valor: (f) => NOMBRE_TIPO_OPCION[f.tipo] ?? f.tipo },
            { titulo: 'Veces', valor: (f) => numero(f.veces), num: true },
            { titulo: 'Pares', valor: (f) => numero(f.pares), num: true },
            { titulo: 'Recargo', valor: (f) => precio(f.ingresos_recargo), num: true },
        ],
        vacio: 'Ningún pedido personalizado en este periodo.',
    });
}

// =============================================================================
// Exportación de pedidos
// =============================================================================

/**
 * Dos enlaces de descarga (CSV para Excel, JSON para otro programa) con el
 * periodo de arriba y un estado opcional. Son enlaces normales: el navegador
 * descarga el archivo con la cookie de la sesión y el nombre que pone el
 * servidor. La exportación incluye los cancelados (es una copia de los datos,
 * no un cálculo de ventas) salvo que se elija un estado.
 */
function bloqueExportar(params) {
    const caja = crear('section', 'tarjeta');
    const cabeza = crear('div', 'tarjeta__cabeza');
    cabeza.append(
        crear('h2', 'tarjeta__titulo', 'Exportar pedidos'),
        crear('p', 'tarjeta__meta', `Una fila por línea de pedido · ${textoPeriodo(params)}`),
    );
    const estado = desplegable([['', 'Todos (también cancelados)'], ...ESTADOS.map((e) => [e, NOMBRE_ESTADO[e]])]);
    const csv = crear('a', 'control control--primario', 'Descargar CSV');
    const json = crear('a', 'control', 'Descargar JSON');
    const enlaces = () => {
        for (const [enlace, formato] of [[csv, 'csv'], [json, 'json']]) {
            const consulta = new URLSearchParams(Object.entries({
                formato, estado: estado.value, desde: params.desde ?? '', hasta: params.hasta ?? '',
            }).filter(([, v]) => v));
            enlace.href = `/api/gestion/exportar/pedidos?${consulta}`;
            enlace.setAttribute('download', '');
        }
    };
    estado.addEventListener('change', enlaces);
    enlaces();

    const fila = crear('div', 'exportar');
    fila.append(campoFiltro('Estado', estado), csv, json);
    caja.append(cabeza, fila);
    return caja;
}
