/*
 * Piezas que comparten las secciones del panel: nombres de los estados,
 * formato de fechas y cifras, etiquetas de estado y los avisos de error.
 * Todo lo que llega de la API se pinta con textContent (a través de crear()).
 */

import { crear, sesionCaducada as sesionCaducadaApi } from '../api.js';

/** Orden del recorrido de un pedido; «cancelado» va aparte porque no es un paso */
export const ESTADOS = ['pendiente', 'confirmado', 'preparacion', 'enviado', 'entregado', 'cancelado'];

export const NOMBRE_ESTADO = {
    pendiente: 'Pendiente',
    confirmado: 'Confirmado',
    preparacion: 'En preparación',
    enviado: 'Enviado',
    entregado: 'Entregado',
    cancelado: 'Cancelado',
};

const numeros = new Intl.NumberFormat('es-ES');

export function numero(valor) {
    return numeros.format(valor);
}

/** «1 par» / «3 pares»: el plural lo decide la cifra */
export function contar(valor, singular, plural) {
    return `${numero(valor)} ${valor === 1 ? singular : plural}`;
}

/**
 * «2026-11-11T18:24:09+01:00» -> «11/11/2026 18:24». Se lee el texto y no se
 * pasa por Date: la hora que se ve es la de la tienda, no la del navegador.
 */
export function fechaCorta(iso) {
    if (!iso) {
        return '—';
    }
    const [fecha, hora = ''] = String(iso).split('T');
    const [anio, mes, dia] = fecha.split('-');
    return `${dia}/${mes}/${anio} ${hora.slice(0, 5)}`.trim();
}

/** Etiqueta discreta del estado; el color solo marca lo que pide una decisión */
export function etiquetaEstado(estado) {
    return crear('span', `estado estado--${estado}`, NOMBRE_ESTADO[estado] ?? estado);
}

/**
 * Lleva al login si la sesión ha caducado (401, o el 403 CSRF que api.js
 * convierte en 401) y, al entrar, de vuelta a la misma sección; devuelve true
 * si lo ha hecho.
 */
export function sesionCaducada(error) {
    return sesionCaducadaApi(error, '/panel' + window.location.hash);
}

/** Frase para la persona según el código de la respuesta (contrato, 9.1) */
export function textoError(error) {
    if (error?.estado === 0) {
        return error.message;
    }
    if (error?.estado === 403) {
        return `No tienes permiso: ${error.message}`;
    }
    if (error?.estado === 500 && error.detalles?.referencia) {
        return `${error.message}. Referencia: ${error.detalles.referencia}`;
    }
    return error?.message ?? 'Algo ha fallado. Vuelve a intentarlo.';
}

/**
 * Aviso de error dentro del bloque que ha fallado (nunca a pantalla completa),
 * con un botón para reintentar.
 */
export function bloqueError(mensaje, reintentar) {
    const caja = crear('div', 'aviso aviso--error');
    caja.setAttribute('role', 'alert');
    caja.append(crear('p', '', mensaje));
    if (reintentar) {
        const boton = crear('button', 'control control--texto', 'Reintentar');
        boton.type = 'button';
        boton.addEventListener('click', reintentar);
        caja.append(boton);
    }
    return caja;
}

/** Cabecera común de una sección: h1, una línea con el dato que importa y acciones */
export function cabeceraSeccion(titulo) {
    const cabecera = crear('header', 'seccion__cabeza');
    const textos = crear('div', 'seccion__textos');
    const h1 = crear('h1', 'seccion__titulo', titulo);
    h1.id = 'panel-titulo';
    h1.tabIndex = -1;
    const linea = crear('p', 'seccion__linea');
    textos.append(h1, linea);
    const acciones = crear('div', 'seccion__acciones');
    cabecera.append(textos, acciones);
    return { cabecera, linea, acciones };
}

/** «Actualizado a las 18:24», con la hora del navegador: es cuándo se pidieron los datos */
export function horaActual() {
    const ahora = new Date();
    return `${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`;
}

/** Hash de una sección con sus parámetros; los vacíos no se escriben */
export function hashDe(id, params = {}) {
    const consulta = new URLSearchParams(
        Object.entries(params).filter(([, valor]) => valor !== undefined && valor !== null && valor !== '')
    ).toString();
    return `#${id}${consulta ? '?' + consulta : ''}`;
}
