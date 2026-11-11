/*
 * Piezas que comparten las secciones de análisis y control del panel
 * (informes, informes de stock, consultas y auditoría): el panel lateral, el
 * paginador, los campos con etiqueta y el filtro de periodo.
 *
 * Van aparte de comun.js para que cada parte del panel crezca en su archivo.
 * Como en el resto del panel, lo que llega de la API se pinta con textContent.
 */

import { cerrarDialogo, crear, prepararDialogo } from '../api.js';
import { numero } from './comun.js';

const SVG_CERRAR = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 3.5l9 9m0-9l-9 9" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>';

/**
 * Panel lateral (<dialog>) con el aspecto del detalle de un pedido. alCerrar
 * se llama al cerrarse por cualquier vía (Escape, fondo o la X), para que la
 * sección quite su parámetro de la URL; al salir de la sección se cierra solo.
 */
export function lateral(ctx, alCerrar) {
    const elemento = crear('dialog', 'detalle');
    elemento.setAttribute('aria-labelledby', 'analisis-lateral-titulo');
    const caja = crear('div', 'detalle__caja');
    elemento.append(caja);
    prepararDialogo(elemento);
    elemento.addEventListener('close', () => {
        if (!ctx.senal.aborted) {
            alCerrar();
        }
    });
    ctx.senal.addEventListener('abort', () => {
        if (elemento.open) {
            elemento.close();
        }
    });
    return {
        elemento,
        caja,
        abrir() {
            if (!elemento.open) {
                elemento.showModal();
            }
        },
        cerrar() {
            cerrarDialogo(elemento);
        },
    };
}

/** Cabecera fija del lateral: título, etiquetas, la X y una línea de datos */
export function cabezaLateral(panel, titulo, { etiquetas = [], meta = '', textoCerrar = 'Cerrar' } = {}) {
    const cabeza = crear('header', 'detalle__cabeza');
    const fila = crear('div', 'detalle__fila-titulo');
    const h2 = crear('h2', 'detalle__titulo', titulo);
    h2.id = 'analisis-lateral-titulo';
    h2.tabIndex = -1;
    fila.append(h2, ...etiquetas);
    const cerrar = crear('button', 'control control--icono');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', textoCerrar);
    cerrar.innerHTML = SVG_CERRAR;
    cerrar.addEventListener('click', () => panel.cerrar());
    cabeza.append(fila, cerrar);
    if (meta) {
        cabeza.append(crear('p', 'detalle__meta', meta));
    }
    return cabeza;
}

/** Paginador como el de pedidos; enlaceDe(pagina) devuelve el hash de esa página */
export function pintarPaginas(nav, { pagina, por_pagina: porPagina, total, paginas }, enlaceDe) {
    nav.replaceChildren();
    if (total === 0) {
        return;
    }
    const desde = (pagina - 1) * porPagina + 1;
    const hasta = Math.min(pagina * porPagina, total);
    nav.append(crear('p', 'paginas__rango num', `${numero(desde)}–${numero(hasta)} de ${numero(total)}`));

    const botones = crear('div', 'paginas__botones');
    for (const [texto, destino, activo] of [
        ['Anterior', pagina - 1, pagina > 1],
        ['Siguiente', pagina + 1, pagina < paginas],
    ]) {
        if (activo) {
            const enlace = crear('a', 'control', texto);
            enlace.href = enlaceDe(destino);
            botones.append(enlace);
        } else {
            // Sin enlace: uno desactivado seguiría recibiendo el foco
            const apagado = crear('span', 'control control--apagado', texto);
            apagado.setAttribute('aria-disabled', 'true');
            botones.append(apagado);
        }
    }
    nav.append(botones);
}

/** Campo con su etiqueta visible encima: <label>texto<control></label> */
export function campo(texto, control) {
    const etiqueta = crear('label', 'barra-filtros__campo');
    etiqueta.append(crear('span', '', texto), control);
    return etiqueta;
}

/** <select class="campo-texto"> con [valor, texto] */
export function desplegable(opciones, valor = '') {
    const select = crear('select', 'campo-texto');
    for (const [clave, texto] of opciones) {
        const opcion = crear('option', '', texto);
        opcion.value = clave;
        select.append(opcion);
    }
    select.value = valor;
    return select;
}

/** Fecha local de hoy (o de hace N días) como AAAA-MM-DD, la que espera la API */
export function diaLocal(haceDias = 0) {
    const fecha = new Date();
    fecha.setDate(fecha.getDate() - haceDias);
    return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
}

/** «2026-11-27» -> «27/11/2026», leyendo el texto para que la zona horaria no mueva el día */
export function fechaDia(iso) {
    if (!iso) {
        return '—';
    }
    const [anio, mes, dia] = String(iso).slice(0, 10).split('-');
    return `${dia}/${mes}/${anio}`;
}

/**
 * Periodos rápidos del filtro de fechas: lo que más se pide va primero y el
 * rango a mano queda en los dos campos de al lado.
 */
export const PERIODOS = [
    ['todo', 'Todo', () => ({ desde: '', hasta: '' })],
    ['30', 'Últimos 30 días', () => ({ desde: diaLocal(29), hasta: diaLocal() })],
    ['90', 'Últimos 90 días', () => ({ desde: diaLocal(89), hasta: diaLocal() })],
    ['anio', 'Este año', () => ({ desde: `${new Date().getFullYear()}-01-01`, hasta: diaLocal() })],
];

/** Qué periodo rápido corresponde a unas fechas (o null si es un rango a mano) */
export function periodoDe(desde = '', hasta = '') {
    return PERIODOS.find(([, , rango]) => {
        const r = rango();
        return r.desde === desde && r.hasta === hasta;
    })?.[0] ?? null;
}
