/*
 * Sección «Consultas» (permiso consultas.gestionar, solo administración): los
 * mensajes del formulario de contacto, filtrados por estado, y el cambio de
 * estado desde el panel lateral.
 *
 * API: GET /api/gestion/consultas?estado&pagina y PATCH /api/gestion/consultas/{id}
 * con {"estado": "pendiente" | "respondida" | "cerrada"}.
 *
 * La respuesta al cliente se escribe en el correo: el panel ofrece el enlace
 * mailto con el asunto ya puesto y, al volver, se marca como respondida.
 * Entre los tres estados no hay orden obligatorio (una cerrada se reabre).
 */

import { api, crear } from '../api.js';
import { bloqueError, cabeceraSeccion, contar, fechaCorta, hashDe, numero, sesionCaducada, textoError } from './comun.js';
import { cabezaLateral, lateral, pintarPaginas } from './piezas.js';

const POR_PAGINA = 20;
const ESTADOS = ['pendiente', 'respondida', 'cerrada'];
const NOMBRE = { pendiente: 'Pendiente', respondida: 'Respondida', cerrada: 'Cerrada' };
const ACCION = { pendiente: 'Volver a pendiente', respondida: 'Marcar como respondida', cerrada: 'Cerrar' };

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let control = null;
    let controlDetalle = null;
    let filasActuales = [];

    const { cabecera, linea } = cabeceraSeccion('Consultas');
    linea.textContent = 'Cargando las consultas…';

    const barra = crear('div', 'filtros');
    const pestanas = crear('nav', 'pestanas');
    pestanas.setAttribute('aria-label', 'Filtrar por estado');
    const enlaces = new Map();
    for (const estado of ['', ...ESTADOS]) {
        const enlace = crear('a', 'pestana');
        enlace.append(crear('span', '', estado ? NOMBRE[estado] : 'Todas'), crear('span', 'pestana__cifra num', ''));
        pestanas.append(enlace);
        enlaces.set(estado, enlace);
    }
    barra.append(pestanas);

    const bloque = crear('section', 'tarjeta tarjeta--lista');
    bloque.setAttribute('aria-labelledby', 'panel-titulo');
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla tabla--analisis');
    tabla.innerHTML = '<caption class="oculto">Consultas, de la más reciente a la más antigua</caption><thead><tr>'
        + '<th scope="col">Fecha</th><th scope="col">De</th><th scope="col">Asunto</th><th scope="col">Estado</th></tr></thead>';
    const cuerpo = crear('tbody');
    tabla.append(cuerpo);
    envoltorio.append(tabla);
    const aviso = crear('div', 'tarjeta__aviso');
    const paginas = crear('nav', 'paginas');
    paginas.setAttribute('aria-label', 'Páginas de la lista');
    bloque.append(aviso, envoltorio, paginas);

    const panel = lateral(ctx, () => {
        if (params.consulta) {
            ctx.irA('consultas', { ...params, consulta: undefined }, { reemplazar: true });
        }
        cuerpo.querySelector('.tabla__fila--abierta')?.classList.remove('tabla__fila--abierta');
    });
    contenedor.append(cabecera, barra, bloque, panel.elemento);

    cuerpo.addEventListener('click', (evento) => {
        const fila = evento.target.closest('tr[data-id]');
        if (fila && !evento.target.closest('a')) {
            fila.querySelector('a')?.click();
        }
    });

    async function cargar() {
        control?.abort();
        control = new AbortController();
        const senal = control.signal;
        for (const [estado, enlace] of enlaces) {
            enlace.href = hashDe('consultas', { estado });
            if ((params.estado ?? '') === estado) {
                enlace.setAttribute('aria-current', 'true');
            } else {
                enlace.removeAttribute('aria-current');
            }
        }
        bloque.setAttribute('aria-busy', 'true');
        try {
            const filtro = new URLSearchParams({ por_pagina: POR_PAGINA, pagina: params.pagina ?? 1 });
            if (params.estado) {
                filtro.set('estado', params.estado);
            }
            const [listado, ...cuentas] = await Promise.all([
                api(`/api/gestion/consultas?${filtro}`, { senal }),
                ...['', ...ESTADOS].map((estado) => api(`/api/gestion/consultas?por_pagina=1${estado ? '&estado=' + estado : ''}`, { senal })),
            ]);
            ['', ...ESTADOS].forEach((estado, i) => {
                enlaces.get(estado).lastChild.textContent = numero(cuentas[i].paginacion.total);
            });
            const pendientes = cuentas[1].paginacion.total;
            linea.textContent = pendientes === 0
                ? 'No hay consultas pendientes de responder.'
                : `${contar(pendientes, 'consulta espera', 'consultas esperan')} respuesta.`;
            aviso.replaceChildren();
            filasActuales = listado.datos;
            pintarFilas(listado.datos);
            pintarPaginas(paginas, listado.paginacion, (pagina) => hashDe('consultas', { ...params, pagina, consulta: undefined }));
            if (params.consulta) {
                abrirDetalle(params.consulta);
            }
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            aviso.replaceChildren(bloqueError(textoError(error), () => cargar()));
        } finally {
            if (!senal.aborted) {
                bloque.removeAttribute('aria-busy');
            }
        }
    }

    function pintarFilas(consultas) {
        if (consultas.length === 0) {
            const fila = crear('tr', 'tabla__vacia');
            const celda = crear('td', '', 'No hay consultas con este filtro.');
            celda.colSpan = 4;
            fila.append(celda);
            cuerpo.replaceChildren(fila);
            return;
        }
        cuerpo.replaceChildren(...consultas.map((c) => {
            const fila = crear('tr');
            fila.dataset.id = String(c.id);
            fila.classList.toggle('tabla__fila--abierta', String(c.id) === params.consulta);
            const de = crear('td');
            de.append(crear('span', '', c.nombre), crear('span', 'tabla__sub', c.email));
            const asunto = crear('td', 'tabla__corta');
            const enlace = crear('a', 'tabla__enlace', c.asunto);
            enlace.href = hashDe('consultas', { ...params, consulta: c.id });
            asunto.append(enlace);
            const estado = crear('td');
            estado.append(etiqueta(c.estado));
            fila.append(crear('td', 'tabla__fecha', fechaCorta(c.fecha_consulta)), de, asunto, estado);
            return fila;
        }));
    }

    /** La consulta ya viene en la lista (la API no tiene GET de una sola): se pinta de ahí */
    function abrirDetalle(id) {
        const consulta = filasActuales.find((c) => String(c.id) === String(id));
        panel.abrir();
        for (const fila of cuerpo.querySelectorAll('tr[data-id]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.id === String(id));
        }
        if (!consulta) {
            panel.caja.replaceChildren(cabezaLateral(panel, 'Consulta'), bloqueError('Esta consulta no está en la página de la lista que se ve.'));
            return;
        }
        pintarDetalle(consulta);
    }

    function pintarDetalle(c) {
        const cabeza = cabezaLateral(panel, c.asunto, {
            etiquetas: [etiqueta(c.estado)],
            meta: `${c.nombre} · ${c.email} · ${fechaCorta(c.fecha_consulta)}`,
            textoCerrar: 'Cerrar la consulta',
        });

        const acciones = crear('section', 'detalle__acciones');
        const botones = crear('div', 'detalle__botones');
        const responder = crear('a', 'control', 'Responder por email');
        responder.href = `mailto:${encodeURIComponent(c.email)}?subject=${encodeURIComponent('Re: ' + c.asunto)}`;
        botones.append(responder);
        const errorEstado = crear('p', 'paso__error');
        errorEstado.setAttribute('role', 'alert');
        for (const estado of ESTADOS.filter((e) => e !== c.estado)) {
            const boton = crear('button', estado === 'respondida' ? 'control control--primario' : 'control', ACCION[estado]);
            boton.type = 'button';
            boton.addEventListener('click', () => cambiarEstado(c, estado, botones, errorEstado));
            botones.append(boton);
        }
        acciones.append(botones, errorEstado);

        const mensaje = crear('section', 'detalle__bloque');
        mensaje.append(crear('h3', 'detalle__subtitulo', 'Mensaje'), crear('p', 'mensaje-consulta', c.mensaje));
        panel.caja.replaceChildren(cabeza, acciones, mensaje);
    }

    async function cambiarEstado(c, estado, botones, errorEstado) {
        controlDetalle?.abort();
        controlDetalle = new AbortController();
        for (const boton of botones.querySelectorAll('button')) {
            boton.disabled = true;
        }
        errorEstado.textContent = '';
        try {
            const { datos } = await api(`/api/gestion/consultas/${c.id}`, {
                metodo: 'PATCH', cuerpo: { estado }, senal: controlDetalle.signal,
            });
            filasActuales = filasActuales.map((f) => (f.id === datos.id ? datos : f));
            pintarDetalle(datos);
            panel.caja.querySelector('#analisis-lateral-titulo')?.focus();
            ctx.anunciar(`Consulta ${NOMBRE[estado].toLowerCase()}`);
            ctx.avisarCambio('consultas');
            cargar();
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            errorEstado.textContent = textoError(error);
            for (const boton of botones.querySelectorAll('button')) {
                boton.disabled = false;
            }
        }
    }

    cargar();
    ctx.senal.addEventListener('abort', () => {
        control?.abort();
        controlDetalle?.abort();
    });

    return {
        actualizar(nuevos) {
            const anteriores = params;
            params = limpiar(nuevos);
            if (anteriores.estado !== params.estado || anteriores.pagina !== params.pagina) {
                cargar();
            } else if (params.consulta) {
                abrirDetalle(params.consulta);
            } else if (panel.elemento.open) {
                panel.cerrar();
            }
        },
    };
}

function limpiar(entrada) {
    return {
        estado: ESTADOS.includes(entrada.estado) ? entrada.estado : undefined,
        pagina: /^[1-9]\d{0,4}$/.test(entrada.pagina ?? '') ? entrada.pagina : undefined,
        consulta: /^[1-9]\d{0,9}$/.test(entrada.consulta ?? '') ? entrada.consulta : undefined,
    };
}

/** Pendiente pide una decisión (como un pedido pendiente); las otras dos, no */
function etiqueta(estado) {
    return crear('span', `estado ${estado === 'pendiente' ? 'estado--pendiente' : 'estado--revisado'}`, NOMBRE[estado] ?? estado);
}
