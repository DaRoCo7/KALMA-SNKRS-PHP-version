/*
 * Estructura del panel: elige la sección por el hash de la URL, carga su
 * módulo y le da un contexto común.
 *
 * Por qué hash (/panel#pedidos?estado=pendiente) y no subrutas: el servidor
 * sirve una sola página y cada sección pide sus datos a la API, así que una
 * sección nueva no necesita ruta PHP; los filtros y el pedido abierto quedan
 * en la URL (se pueden recargar y compartir) y el botón Atrás funciona.
 *
 * Añadir una sección:
 *   1. Una entrada en PanelController::SECCIONES (id, nombre, grupo, permiso).
 *   2. public/js/panel/<id>.js con `export function montar(contenedor, ctx)`.
 *      Puede devolver { actualizar(params) } para reaccionar a los cambios de
 *      filtros sin volver a montarse. Los escuchadores que registre con
 *      { signal: ctx.senal } se quitan solos al salir de la sección.
 *
 * ctx = { rol, puede(permiso), transiciones, params, senal,
 *         irA(seccion, params, { reemplazar }), anunciar(texto), avisarCambio(origen) }
 */

import { crear } from '../api.js';
import { bloqueError, hashDe } from './comun.js';

const raiz = document.getElementById('panel');
const trabajo = document.getElementById('panel-trabajo');
const nota = document.getElementById('panel-nota');
const botonMenu = raiz.querySelector('.panel__boton-menu');
const enlaces = [...raiz.querySelectorAll('[data-seccion]')];

// Solo las secciones que el servidor ha pintado: las demás no existen para este rol
const disponibles = enlaces.map((enlace) => enlace.dataset.seccion);
const permisos = new Set(JSON.parse(raiz.dataset.permisos || '[]'));
const transiciones = JSON.parse(raiz.dataset.transiciones || '{}');

raiz.classList.add('panel--listo');

/** «#pedidos?estado=pendiente» -> { id: 'pedidos', params: { estado: 'pendiente' } } */
function leerHash() {
    const [id, consulta = ''] = window.location.hash.slice(1).split('?');
    return {
        id: disponibles.includes(id) ? id : disponibles[0],
        valido: disponibles.includes(id),
        params: Object.fromEntries(new URLSearchParams(consulta)),
    };
}

function irA(id, params = {}, { reemplazar = false } = {}) {
    const destino = hashDe(id, params);
    if (destino === window.location.hash) {
        return;
    }
    if (reemplazar) {
        // replaceState no lanza hashchange: se aplica a mano
        history.replaceState(null, '', destino);
        aplicar();
    } else {
        window.location.hash = destino;
    }
}

let temporizadorNota = 0;

/** Confirmación breve abajo a la derecha; el lector de pantalla la lee por el role=status */
function anunciar(texto) {
    nota.textContent = texto;
    nota.classList.add('panel__nota--visible');
    window.clearTimeout(temporizadorNota);
    temporizadorNota = window.setTimeout(() => nota.classList.remove('panel__nota--visible'), 3200);
}

/**
 * Una sección ha cambiado datos: las que los muestren los vuelven a pedir. El
 * origen deja que quien avisa no se recargue dos veces.
 */
function avisarCambio(origen) {
    document.dispatchEvent(new CustomEvent('panel:cambio', { detail: { origen } }));
}

function marcarActiva(id) {
    for (const enlace of enlaces) {
        if (enlace.dataset.seccion === id) {
            enlace.setAttribute('aria-current', 'page');
        } else {
            enlace.removeAttribute('aria-current');
        }
    }
}

let actual = null;   // { id, instancia, control, params }: params son los últimos del hash
let primeraCarga = true;

async function aplicar() {
    if (disponibles.length === 0) {
        trabajo.replaceChildren(crear('p', 'aviso', 'Tu cuenta no tiene ninguna sección del panel asignada.'));
        return;
    }

    const { id, valido, params } = leerHash();
    if (!valido) {
        history.replaceState(null, '', hashDe(id, params));
    }
    marcarActiva(id);

    // Misma sección: solo cambian los filtros o el pedido abierto. Si su módulo
    // aún se está importando, se guardan y se aplican al terminar de montarse
    if (actual?.id === id) {
        actual.params = params;
        actual.instancia?.actualizar?.(params);
        return;
    }

    actual?.control.abort();
    const control = new AbortController();
    const seccion = { id, instancia: null, control, params };
    actual = seccion;
    trabajo.replaceChildren();
    const nombre = enlaces.find((enlace) => enlace.dataset.seccion === id)?.textContent.trim() ?? id;
    document.title = `${nombre} | Panel | KALMA SNKRS`;

    try {
        const modulo = await import(`./${id}.js`);
        if (control.signal.aborted) {
            return;
        }
        seccion.instancia = modulo.montar(trabajo, {
            rol: raiz.dataset.rol,
            puede: (permiso) => permisos.has(permiso),
            transiciones,
            params,
            senal: control.signal,
            irA,
            anunciar,
            avisarCambio,
        }) ?? null;
        // El hash ha cambiado mientras llegaba el módulo: se aplica lo último
        if (seccion.params !== params) {
            seccion.instancia?.actualizar?.(seccion.params);
        }
    } catch (error) {
        console.error(error);
        trabajo.replaceChildren(bloqueError('No se ha podido cargar esta sección. Recarga la página.'));
    }

    // Al cambiar de sección con el menú, el foco va al título nuevo; en la primera
    // carga no, para no saltarse la barra lateral a quien navega con teclado
    if (!primeraCarga) {
        trabajo.querySelector('h1')?.focus({ preventScroll: true });
        window.scrollTo(0, 0);
    }
    primeraCarga = false;
}

// --- Menú del móvil --------------------------------------------------------------

function abrirMenu(abrir) {
    raiz.classList.toggle('panel--menu-abierto', abrir);
    botonMenu.setAttribute('aria-expanded', String(abrir));
}

botonMenu.addEventListener('click', () => abrirMenu(!raiz.classList.contains('panel--menu-abierto')));
for (const enlace of enlaces) {
    enlace.addEventListener('click', () => abrirMenu(false));
}
document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && raiz.classList.contains('panel--menu-abierto')) {
        abrirMenu(false);
        botonMenu.focus();
    }
});

window.addEventListener('hashchange', aplicar);
aplicar();

// Contadores de la barra lateral (informes de stock pendientes); si falla, el panel sigue igual
import('./avisos.js').catch((error) => console.error(error));
