/*
 * Piezas que comparten las secciones de inventario del panel (stock,
 * albaranes y productos): el panel lateral, la paginación, la foto de una
 * zapatilla, los campos de formulario con su error y el envío del CSV.
 *
 * Van aparte de comun.js para que cada parte del panel crezca en su archivo.
 * Como en el resto del panel, lo que llega de la API se pinta con textContent.
 */

import { ErrorApi, api, cerrarDialogo, crear, prepararDialogo } from '../api.js';
import { hashDe, numero } from './comun.js';

const SVG_CERRAR = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 3.5l9 9m0-9l-9 9" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>';

/**
 * Panel lateral (<dialog>) con el mismo aspecto que el detalle de un pedido.
 * alCerrar se llama al cerrarse por cualquier vía (Escape, fondo, la X o al
 * salir de la sección), para que la sección quite su parámetro de la URL.
 */
export function crearLateral(ctx, alCerrar) {
    const elemento = crear('dialog', 'detalle');
    elemento.setAttribute('aria-labelledby', 'lateral-titulo');
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

/** Cabecera fija del panel lateral: título, etiquetas opcionales, la X y una línea de datos */
export function cabezaLateral(lateral, titulo, { etiquetas = [], meta = '', textoCerrar = 'Cerrar' } = {}) {
    const cabeza = crear('header', 'detalle__cabeza');
    const fila = crear('div', 'detalle__fila-titulo');
    const h2 = crear('h2', 'detalle__titulo', titulo);
    h2.id = 'lateral-titulo';
    h2.tabIndex = -1;
    fila.append(h2, ...etiquetas);
    const cerrar = crear('button', 'control control--icono');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', textoCerrar);
    cerrar.innerHTML = SVG_CERRAR;
    cerrar.addEventListener('click', () => lateral.cerrar());
    cabeza.append(fila, cerrar);
    if (meta) {
        cabeza.append(crear('p', 'detalle__meta', meta));
    }
    return cabeza;
}

/** Etiqueta pequeña: lanzamiento, desactivada, nueva... (variantes en panel-inventario.css) */
export function etiqueta(texto, variante = '') {
    return crear('span', `marca-inv${variante ? ` marca-inv--${variante}` : ''}`, texto);
}

/** «2026-11-27» -> «27/11/2026»; sin pasar por Date para que la zona horaria no mueva el día */
export function fechaDia(iso) {
    if (!iso) {
        return '—';
    }
    const [anio, mes, dia] = String(iso).slice(0, 10).split('-');
    return `${dia}/${mes}/${anio}`;
}

/** Cantidad con signo: «+3», «−2» (el signo menos tipográfico, que se lee mejor en cifras tabulares) */
export function conSigno(valor) {
    return valor > 0 ? `+${numero(valor)}` : `−${numero(Math.abs(valor))}`;
}

/**
 * Foto de una zapatilla por convención (public/img/productos/<código>.webp).
 * Si no existe, queda un hueco gris del mismo tamaño y la tabla no baila.
 */
export function fotoProducto(codigo, tamano = 40) {
    const foto = crear('img', 'foto-inv');
    Object.assign(foto, {
        src: `/img/productos/${String(codigo).toLowerCase()}.webp`,
        alt: '', width: tamano, height: tamano, loading: 'lazy', decoding: 'async',
    });
    // Un <img> roto enseña el icono del navegador: se cambia por un hueco del mismo tamaño
    foto.addEventListener('error', () => {
        const hueco = crear('span', 'foto-inv foto-inv--sin');
        hueco.style.width = hueco.style.height = `${tamano}px`;
        hueco.setAttribute('aria-hidden', 'true');
        foto.replaceWith(hueco);
    }, { once: true });
    return foto;
}

/**
 * Paginador como el de pedidos: rango y Anterior/Siguiente como enlaces del hash.
 * enlaceDe(pagina) devuelve el hash de esa página.
 */
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
            const apagado = crear('span', 'control control--apagado', texto);
            apagado.setAttribute('aria-disabled', 'true');
            botones.append(apagado);
        }
    }
    nav.append(botones);
}

/** Filas grises mientras llega la primera carga de una tabla */
export function filasEsqueleto(columnas, filas = 6) {
    return Array.from({ length: filas }, () => {
        const fila = crear('tr', 'tabla__esqueleto');
        for (let i = 0; i < columnas; i++) {
            const celda = crear('td');
            celda.append(crear('span', 'hueco'));
            fila.append(celda);
        }
        return fila;
    });
}

/** Fila única de «no hay nada» con un enlace opcional para quitar los filtros */
export function filaVacia(columnas, texto, seccionSinFiltros = null) {
    const fila = crear('tr', 'tabla__vacia');
    const celda = crear('td');
    celda.colSpan = columnas;
    celda.append(crear('span', '', texto + ' '));
    if (seccionSinFiltros) {
        const quitar = crear('a', 'tabla__enlace', 'Quitar los filtros');
        quitar.href = hashDe(seccionSinFiltros);
        celda.append(quitar);
    }
    fila.append(celda);
    return fila;
}

/**
 * Campo de formulario con su etiqueta, una ayuda opcional y el hueco para el
 * error. El error se enlaza con aria-describedby para que el lector de
 * pantalla lo lea al volver al campo.
 */
export function campo({ id, etiqueta: texto, control, ayuda = '', clase = '' }) {
    const caja = crear('div', `campo-inv${clase ? ` ${clase}` : ''}`);
    const label = crear('label', 'campo-inv__etiqueta', texto);
    label.htmlFor = id;
    control.id = id;
    const error = crear('p', 'campo-inv__error');
    error.id = `${id}-error`;
    const descritos = [error.id];
    caja.append(label, control);
    if (ayuda) {
        const nota = crear('p', 'campo-inv__ayuda', ayuda);
        nota.id = `${id}-ayuda`;
        descritos.unshift(nota.id);
        caja.append(nota);
    }
    caja.append(error);
    control.setAttribute('aria-describedby', descritos.join(' '));
    return { caja, control, error };
}

/**
 * Pinta los errores de un 400 ({"campo": "motivo"}) junto a sus campos.
 * claves: { nombre_en_la_api: { control, error } }. Las claves con posición
 * (tallas.2) van al campo base (tallas). Devuelve los errores que no tienen
 * campo en el formulario, para enseñarlos arriba.
 */
export function mostrarErrores(campos, errores) {
    for (const { control, error } of Object.values(campos)) {
        error.textContent = '';
        control.removeAttribute('aria-invalid');
    }
    const sueltos = [];
    let primero = null;
    for (const [clave, motivo] of Object.entries(errores ?? {})) {
        const base = clave.split('.')[0];
        const destino = campos[clave] ?? campos[base];
        if (!destino) {
            sueltos.push(motivo);
            continue;
        }
        const posicion = clave.includes('.') ? `Valor ${Number(clave.split('.')[1]) + 1}: ` : '';
        destino.error.textContent = [destino.error.textContent, posicion + motivo].filter(Boolean).join(' · ');
        destino.control.setAttribute('aria-invalid', 'true');
        primero ??= destino.control;
    }
    primero?.focus();
    return sueltos;
}

/**
 * Envía un cuerpo de texto (el CSV del albarán) con su tipo. api() de api.js
 * convierte el cuerpo en JSON, así que aquí se hace la petición a mano con el
 * mismo token CSRF (el de la página: api.js lo mantiene al día en el <meta>).
 */
export async function enviarTexto(ruta, texto, tipo, senal) {
    let respuesta;
    try {
        respuesta = await fetch(ruta, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': `${tipo}; charset=utf-8`,
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: texto,
            credentials: 'same-origin',
            signal: senal,
        });
    } catch (error) {
        if (error.name === 'AbortError') {
            throw error;
        }
        throw new ErrorApi(0, 'No hay conexión con la tienda. Comprueba tu red y vuelve a intentarlo.');
    }
    const json = await respuesta.json().catch(() => null);
    if (!respuesta.ok) {
        const error = json?.error ?? {};
        throw new ErrorApi(respuesta.status, error.mensaje ?? 'Algo ha fallado. Vuelve a intentarlo.', error.detalles);
    }
    return json ?? {};
}

/**
 * Todas las páginas de un listado (hasta un tope prudente). Se usa en el
 * stock, donde la tabla agrupa las tallas por zapatilla: con páginas de
 * tallas, una zapatilla podría quedar partida entre dos páginas.
 */
export async function todasLasPaginas(ruta, senal, { porPagina = 100, tope = 20 } = {}) {
    const separador = ruta.includes('?') ? '&' : '?';
    const primera = await api(`${ruta}${separador}por_pagina=${porPagina}&pagina=1`, { senal });
    const filas = [...primera.datos];
    const paginas = Math.min(primera.paginacion.paginas, tope);
    if (paginas > 1) {
        const resto = await Promise.all(
            Array.from({ length: paginas - 1 }, (_, i) => api(`${ruta}${separador}por_pagina=${porPagina}&pagina=${i + 2}`, { senal }))
        );
        for (const pagina of resto) {
            filas.push(...pagina.datos);
        }
    }
    return filas;
}
