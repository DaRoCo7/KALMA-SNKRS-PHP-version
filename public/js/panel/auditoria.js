/*
 * Sección «Auditoría» (permiso auditoria.ver, solo administración): el
 * registro de eventos de negocio con filtros y el detalle JSON de cada uno.
 *
 * API: GET /api/gestion/auditoria?tipo&entidad&resultado&usuario_id&pagina&por_pagina
 * (la API no filtra por fechas: el registro va del más reciente al más antiguo).
 *
 * El tipo se escribe o se elige de una lista de sugerencias (los tipos que
 * escribe el código hoy); la lista no limita: si aparece un tipo nuevo, se
 * puede buscar igual. El usuario sale de /api/usuarios (usuarios.gestionar).
 */

import { api, crear } from '../api.js';
import { bloqueError, cabeceraSeccion, contar, fechaCorta, hashDe, sesionCaducada, textoError } from './comun.js';
import { cabezaLateral, campo, desplegable, lateral, pintarPaginas } from './analisis-comun.js';

const POR_PAGINA = 50;

const TIPOS_CONOCIDOS = [
    'auth.login', 'usuario.registrar', 'acceso.denegado', 'perfil.editar',
    'pedido.crear', 'pedido.ver', 'pedido.cancelar', 'pedido.cambiar_estado', 'pedidos.exportar',
    'stock.ajustar', 'albaran.cargar', 'producto.crear', 'producto.editar', 'producto.desactivar',
    'consulta.crear', 'consulta.cambiar_estado', 'informe_stock.enviar', 'informe_stock.revisar',
];
const ENTIDADES = ['pedido', 'variante', 'producto', 'albaran', 'usuario', 'consulta', 'informe_stock', 'ruta'];
const RESULTADOS = { exito: 'Éxito', error: 'Error', denegado: 'Denegado' };

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let control = null;
    let eventos = [];

    const { cabecera, linea } = cabeceraSeccion('Auditoría');
    linea.textContent = 'Quién hizo qué, cuándo y con qué resultado.';

    // --- Filtros -----------------------------------------------------------------
    const filtros = crear('form', 'barra-filtros');
    filtros.setAttribute('aria-label', 'Filtros de la auditoría');
    const tipo = crear('input', 'campo-texto');
    Object.assign(tipo, { type: 'search', placeholder: 'ej. pedido.crear', maxLength: 50, autocomplete: 'off' });
    tipo.setAttribute('list', 'auditoria-tipos');
    const sugerencias = crear('datalist');
    sugerencias.id = 'auditoria-tipos';
    for (const t of TIPOS_CONOCIDOS) {
        const opcion = crear('option');
        opcion.value = t;
        sugerencias.append(opcion);
    }
    const entidad = desplegable([['', 'Todas'], ...ENTIDADES.map((e) => [e, e])]);
    const resultado = desplegable([['', 'Todos'], ...Object.entries(RESULTADOS)]);
    const usuario = desplegable([['', 'Todos']]);
    const aplicar = crear('button', 'control', 'Filtrar');
    aplicar.type = 'submit';
    const limpiarFiltros = crear('a', 'control control--texto', 'Quitar filtros');
    limpiarFiltros.href = hashDe('auditoria');
    const errorFiltros = crear('p', 'barra-filtros__error');
    errorFiltros.setAttribute('role', 'alert');
    filtros.append(campo('Evento', tipo), sugerencias, campo('Entidad', entidad), campo('Resultado', resultado),
        campo('Usuario', usuario), aplicar, limpiarFiltros, errorFiltros);

    filtros.addEventListener('submit', (evento) => {
        evento.preventDefault();
        irCon({ tipo: tipo.value.trim(), entidad: entidad.value, resultado: resultado.value, usuario_id: usuario.value });
    });
    for (const select of [entidad, resultado, usuario]) {
        select.addEventListener('change', () => filtros.requestSubmit());
    }

    const bloque = crear('section', 'tarjeta tarjeta--lista');
    bloque.setAttribute('aria-labelledby', 'panel-titulo');
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla tabla--analisis');
    tabla.innerHTML = '<caption class="oculto">Eventos de auditoría, del más reciente al más antiguo</caption><thead><tr>'
        + '<th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Evento</th>'
        + '<th scope="col">Entidad</th><th scope="col">Resultado</th><th scope="col">IP</th></tr></thead>';
    const cuerpo = crear('tbody');
    tabla.append(cuerpo);
    envoltorio.append(tabla);
    const aviso = crear('div', 'tarjeta__aviso');
    const paginas = crear('nav', 'paginas');
    paginas.setAttribute('aria-label', 'Páginas del registro');
    bloque.append(aviso, envoltorio, paginas);

    const panel = lateral(ctx, () => {
        if (params.evento) {
            ctx.irA('auditoria', { ...params, evento: undefined }, { reemplazar: true });
        }
        cuerpo.querySelector('.tabla__fila--abierta')?.classList.remove('tabla__fila--abierta');
    });
    contenedor.append(cabecera, filtros, bloque, panel.elemento);

    cuerpo.addEventListener('click', (evento) => {
        const fila = evento.target.closest('tr[data-id]');
        if (fila && !evento.target.closest('a')) {
            fila.querySelector('a')?.click();
        }
    });

    function irCon(cambios) {
        ctx.irA('auditoria', { ...params, ...cambios, pagina: undefined, evento: undefined }, { reemplazar: true });
    }

    function pintarFiltros() {
        tipo.value = params.tipo ?? '';
        entidad.value = params.entidad ?? '';
        resultado.value = params.resultado ?? '';
        usuario.value = params.usuario_id ?? '';
        errorFiltros.textContent = '';
    }

    /** Usuarios para el desplegable (la API pagina de 50 en 50: basta para el equipo y las cuentas de prueba) */
    async function cargarUsuarios() {
        if (!ctx.puede('usuarios.gestionar')) {
            usuario.closest('label').hidden = true;
            return;
        }
        try {
            const { datos } = await api('/api/usuarios?por_pagina=50', { senal: ctx.senal });
            for (const u of datos) {
                const opcion = crear('option', '', `${u.email} (${u.rol})`);
                opcion.value = String(u.id);
                usuario.append(opcion);
            }
            usuario.value = params.usuario_id ?? '';
        } catch (error) {
            if (error.name !== 'AbortError') {
                usuario.closest('label').hidden = true;
            }
        }
    }

    async function cargar() {
        control?.abort();
        control = new AbortController();
        const senal = control.signal;
        bloque.setAttribute('aria-busy', 'true');
        try {
            const consulta = new URLSearchParams(Object.entries({
                tipo: params.tipo, entidad: params.entidad, resultado: params.resultado, usuario_id: params.usuario_id,
                pagina: params.pagina, por_pagina: POR_PAGINA,
            }).filter(([, v]) => v));
            const respuesta = await api(`/api/gestion/auditoria?${consulta}`, { senal });
            eventos = respuesta.datos;
            aviso.replaceChildren();
            const hayFiltros = Boolean(params.tipo || params.entidad || params.resultado || params.usuario_id);
            linea.textContent = `${contar(respuesta.paginacion.total, 'evento', 'eventos')}${hayFiltros ? ' con estos filtros' : ' registrados'}, del más reciente al más antiguo.`;
            pintarFilas();
            pintarPaginas(paginas, respuesta.paginacion, (pagina) => hashDe('auditoria', { ...params, pagina, evento: undefined }));
            if (params.evento) {
                abrirDetalle(params.evento);
            }
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            if (error.estado === 400) {
                errorFiltros.textContent = Object.values(error.detalles?.errores ?? {}).join(' ') || error.message;
                cuerpo.replaceChildren();
                paginas.replaceChildren();
                return;
            }
            aviso.replaceChildren(bloqueError(textoError(error), () => cargar()));
        } finally {
            if (!senal.aborted) {
                bloque.removeAttribute('aria-busy');
            }
        }
    }

    function pintarFilas() {
        if (eventos.length === 0) {
            const fila = crear('tr', 'tabla__vacia');
            const celda = crear('td', '', 'No hay eventos con estos filtros.');
            celda.colSpan = 6;
            fila.append(celda);
            cuerpo.replaceChildren(fila);
            return;
        }
        cuerpo.replaceChildren(...eventos.map((e) => {
            const fila = crear('tr');
            fila.dataset.id = String(e.id);
            fila.classList.toggle('tabla__fila--abierta', String(e.id) === params.evento);
            const fecha = crear('td', 'tabla__fecha');
            const enlace = crear('a', 'tabla__enlace', fechaCorta(e.fecha));
            enlace.href = hashDe('auditoria', { ...params, evento: e.id });
            enlace.setAttribute('aria-label', `Evento ${e.id}, ${e.tipo_evento}, ${fechaCorta(e.fecha)}`);
            fecha.append(enlace);
            fila.append(
                fecha,
                crear('td', 'tabla__corta', e.usuario?.email ?? 'Anónimo'),
                crear('td', 'tabla__codigo', e.tipo_evento),
                crear('td', 'tabla__codigo', entidadDe(e)),
                crear('td', `resultado--${e.resultado}`, RESULTADOS[e.resultado] ?? e.resultado),
                crear('td', 'tabla__codigo', e.ip ?? '—'),
            );
            return fila;
        }));
    }

    /** El evento ya está en la lista que se ve: el detalle se pinta de ahí, sin otra petición */
    function abrirDetalle(id) {
        const e = eventos.find((ev) => String(ev.id) === String(id));
        panel.abrir();
        for (const fila of cuerpo.querySelectorAll('tr[data-id]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.id === String(id));
        }
        if (!e) {
            panel.caja.replaceChildren(cabezaLateral(panel, `Evento ${id}`), bloqueError('Este evento no está en la página del registro que se ve.'));
            return;
        }
        const cabeza = cabezaLateral(panel, e.tipo_evento, {
            etiquetas: [crear('span', `estado resultado--${e.resultado}`, RESULTADOS[e.resultado] ?? e.resultado)],
            meta: `Evento ${e.id} · ${fechaCorta(e.fecha)} · ${e.usuario?.email ?? 'anónimo'} · IP ${e.ip ?? '—'}`,
            textoCerrar: 'Cerrar el evento',
        });
        const datos = crear('section', 'detalle__bloque');
        const lista = crear('dl', 'datos');
        for (const [clave, valor] of [
            ['Entidad', entidadDe(e)],
            ['Usuario', e.usuario ? `${e.usuario.email} (id ${e.usuario.id})` : 'Anónimo o usuario borrado'],
        ]) {
            lista.append(crear('dt', '', clave), crear('dd', '', valor));
        }
        datos.append(crear('h3', 'detalle__subtitulo', 'Evento'), lista);

        const detalle = crear('section', 'detalle__bloque');
        detalle.append(crear('h3', 'detalle__subtitulo', 'Detalle'));
        if (e.detalle === null) {
            detalle.append(crear('p', 'datos__vacio', 'Este evento no guarda detalle.'));
        } else {
            // JSON con sangría en un <pre>: se lee entero y se puede copiar tal cual
            const pre = crear('pre', 'json', JSON.stringify(e.detalle, null, 2));
            pre.tabIndex = 0;
            pre.setAttribute('aria-label', 'Detalle del evento en JSON');
            detalle.append(pre);
        }
        if (e.tipo_evento === 'informe_stock.enviar' || e.entidad === 'informe_stock') {
            const enlace = crear('a', 'control', 'Abrir el informe de stock');
            enlace.href = hashDe('informes-stock', { informe: e.entidad_id ?? e.id });
            const parrafo = crear('p', 'auditoria__enlace');
            parrafo.append(enlace);
            detalle.append(parrafo);
        }
        panel.caja.replaceChildren(cabeza, datos, detalle);
    }

    pintarFiltros();
    cargarUsuarios();
    cargar();
    ctx.senal.addEventListener('abort', () => control?.abort());

    return {
        actualizar(nuevos) {
            const anteriores = params;
            params = limpiar(nuevos);
            pintarFiltros();
            const cambiaLista = ['tipo', 'entidad', 'resultado', 'usuario_id', 'pagina'].some((c) => anteriores[c] !== params[c]);
            if (cambiaLista) {
                cargar();
            } else if (params.evento) {
                abrirDetalle(params.evento);
            } else if (panel.elemento.open) {
                panel.cerrar();
            }
        },
    };
}

/**
 * «pedido #12». El envío de un informe de stock no apunta a otra fila: el
 * informe ES ese evento, así que su número es el id del propio evento.
 */
function entidadDe(e) {
    if (e.tipo_evento === 'informe_stock.enviar') {
        return `informe_stock #${e.id}`;
    }
    return e.entidad_id === null ? e.entidad : `${e.entidad} #${e.entidad_id}`;
}

/** Del hash solo pasa lo que la API aceptaría; el resto se ignora */
function limpiar(entrada) {
    const si = (valor, patron) => (patron.test(valor ?? '') ? valor : undefined);
    return {
        tipo: si(entrada.tipo, /^[a-z_]+(\.[a-z_]+)*$/),
        entidad: si(entrada.entidad, /^[a-z_]+$/),
        resultado: si(entrada.resultado, /^(exito|error|denegado)$/),
        usuario_id: si(entrada.usuario_id, /^[1-9]\d{0,8}$/),
        pagina: si(entrada.pagina, /^[1-9]\d{0,4}$/),
        evento: si(entrada.evento, /^[1-9]\d{0,17}$/),
    };
}
