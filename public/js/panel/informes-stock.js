/*
 * Sección «Informes de stock».
 *
 * Almacén (informes_stock.enviar): elige el informe (poco stock, o sin ventas
 * desde hace N días), ve la vista previa con su gráfica y su tabla, la
 * descarga en CSV y la envía a administración con un comentario. Debajo,
 * sus informes enviados y si ya están revisados.
 *
 * Administración (informes_stock.revisar): la bandeja con los pendientes
 * primero, el detalle de cada uno (la foto de las filas que se envió, no el
 * stock de ahora) y «Marcar como revisado».
 *
 * API: GET  /api/gestion/informes-stock/vista-previa?tipo&dias (y /vista-previa/csv)
 *      POST /api/gestion/informes-stock · GET /api/gestion/informes-stock?estado&pagina
 *      GET  /api/gestion/informes-stock/{id} (y /{id}/csv) · PATCH /{id}/revisado
 *
 * Estado en el hash: #informes-stock?tipo=sin_ventas&dias=60 (almacén) y
 * #informes-stock?estado=pendiente&informe=12 (los dos).
 */

import { api, crear, talla } from '../api.js';
import {
    bloqueError, cabeceraSeccion, contar, fechaCorta, hashDe, numero, sesionCaducada, textoError,
} from './comun.js';
import { cabezaLateral, campoFiltro, lateral, pintarPaginas } from './piezas.js';
import { barras } from './graficas.js';

const POR_PAGINA = 20;
const DIAS_RAPIDOS = [30, 60, 90];
const DIAS_POR_DEFECTO = 60;
const COMENTARIO_MAXIMO = 500;
const EN_GRAFICA = 10;

export const NOMBRE_TIPO = {
    stock_bajo: 'Poco stock',
    sin_ventas: 'Sin ventas',
};

export function montar(contenedor, ctx) {
    const revisa = ctx.puede('informes_stock.revisar');
    let params = limpiar(ctx.params, revisa);

    const { cabecera, linea } = cabeceraSeccion('Informes de stock');
    contenedor.append(cabecera);

    // --- Lista de informes enviados (bandeja de administración o «mis enviados») ---
    const lista = crearLista();
    const panel = lateral(ctx, () => {
        if (params.informe) {
            ctx.irA('informes-stock', { ...params, informe: undefined }, { reemplazar: true });
        }
        lista.cuerpo.querySelector('.tabla__fila--abierta')?.classList.remove('tabla__fila--abierta');
    });

    const generador = revisa ? null : crearGenerador();
    if (generador) {
        linea.textContent = 'Genera un informe, revísalo y envíalo a administración.';
        contenedor.append(generador.elemento);
    }
    contenedor.append(lista.elemento, panel.elemento);

    // ==========================================================================
    // Generador (almacén)
    // ==========================================================================

    function crearGenerador() {
        const elemento = crear('div', 'analisis');
        const caja = crear('section', 'tarjeta');
        const cabeza = crear('div', 'tarjeta__cabeza');
        cabeza.append(crear('h2', 'tarjeta__titulo', 'Nuevo informe'));

        const formulario = crear('form', 'generador');
        const tipos = crear('fieldset', 'generador__tipos');
        tipos.append(crear('legend', '', 'Qué quieres revisar'));
        const radios = {};
        for (const [tipo, nombre, nota] of [
            ['stock_bajo', 'Poco stock', 'Tallas en su stock mínimo o por debajo'],
            ['sin_ventas', 'Sin ventas', 'Zapatillas que no se venden desde hace unos días'],
        ]) {
            const opcion = crear('label', 'opcion-tipo');
            const radio = crear('input');
            Object.assign(radio, { type: 'radio', name: 'tipo-informe', value: tipo });
            opcion.append(radio, crear('span', 'opcion-tipo__nombre', nombre), crear('span', 'opcion-tipo__nota', nota));
            tipos.append(opcion);
            radios[tipo] = radio;
        }

        const filaDias = crear('div', 'generador__fila');
        const rapidos = crear('div', 'barra-filtros__grupo');
        rapidos.setAttribute('role', 'group');
        rapidos.setAttribute('aria-label', 'Días rápidos');
        const botonesDias = new Map();
        for (const dias of DIAS_RAPIDOS) {
            const boton = crear('button', 'control', `${dias} días`);
            boton.type = 'button';
            boton.addEventListener('click', () => ir({ dias }));
            rapidos.append(boton);
            botonesDias.set(dias, boton);
        }
        const campoDias = crear('input', 'campo-texto campo-dias num');
        Object.assign(campoDias, { type: 'number', min: 1, max: 365, step: 1, inputMode: 'numeric', id: 'informe-dias' });
        const errorDias = crear('p', 'barra-filtros__error');
        errorDias.id = 'informe-dias-error';
        campoDias.setAttribute('aria-describedby', errorDias.id);
        const ver = crear('button', 'control', 'Ver');
        ver.type = 'submit';
        filaDias.append(rapidos, campoFiltro('Días sin vender (1 a 365)', campoDias), ver, errorDias);

        formulario.append(tipos, filaDias);
        caja.append(cabeza, formulario);

        const previa = crear('section', 'tarjeta');
        previa.setAttribute('aria-live', 'polite');
        elemento.append(caja, previa);

        for (const radio of Object.values(radios)) {
            radio.addEventListener('change', () => ir({ tipo: radio.value }));
        }
        formulario.addEventListener('submit', (evento) => {
            evento.preventDefault();
            const dias = Number(campoDias.value);
            if (!Number.isInteger(dias) || dias < 1 || dias > 365) {
                errorDias.textContent = 'Escribe un número entero de días entre 1 y 365.';
                campoDias.setAttribute('aria-invalid', 'true');
                campoDias.focus();
                return;
            }
            ir({ dias });
        });

        function ir(cambios) {
            ctx.irA('informes-stock', { ...params, ...cambios, informe: undefined }, { reemplazar: true });
        }

        let control = null;
        let ultimo = null;   // vista previa que se está viendo (lo que se enviará)

        function pintarControles() {
            radios[params.tipo].checked = true;
            filaDias.hidden = params.tipo !== 'sin_ventas';
            campoDias.value = String(params.dias);
            campoDias.removeAttribute('aria-invalid');
            errorDias.textContent = '';
            for (const [dias, boton] of botonesDias) {
                boton.setAttribute('aria-pressed', String(dias === params.dias));
            }
        }

        async function cargar() {
            control?.abort();
            control = new AbortController();
            const senal = control.signal;
            elemento.setAttribute('aria-busy', 'true');
            if (!previa.hasChildNodes()) {
                previa.append(crear('p', 'vacio', 'Calculando la vista previa…'));
            }
            try {
                const { datos } = await api(`/api/gestion/informes-stock/vista-previa?${consulta(params)}`, { senal });
                ultimo = datos;
                pintarPrevia(datos);
            } catch (error) {
                if (error.name === 'AbortError' || sesionCaducada(error)) {
                    return;
                }
                previa.replaceChildren(bloqueError(textoError(error), () => cargar()));
            } finally {
                if (!senal.aborted) {
                    elemento.removeAttribute('aria-busy');
                }
            }
        }

        function pintarPrevia(datos) {
            const cabezaPrevia = crear('div', 'tarjeta__cabeza');
            const descargar = crear('a', 'control', 'Descargar CSV');
            descargar.href = `/api/gestion/informes-stock/vista-previa/csv?${consulta(params)}`;
            descargar.setAttribute('download', '');
            cabezaPrevia.append(
                crear('h2', 'tarjeta__titulo', `Vista previa: ${resumenInforme(datos)}`),
                descargar,
            );
            previa.replaceChildren(cabezaPrevia, ...contenidoInforme(datos), formularioEnvio());
        }

        /**
         * Comentario opcional y el botón de enviar. Solo se mandan el tipo y los
         * días: el servidor vuelve a calcular el informe al guardarlo, así que si
         * el stock ha cambiado desde la vista previa se guarda el dato al día
         */
        function formularioEnvio() {
            const envio = crear('form', 'envio');
            const etiqueta = crear('label', 'paso__etiqueta', 'Comentario para administración (opcional)');
            etiqueta.htmlFor = 'informe-comentario';
            const texto = crear('textarea', 'campo-texto');
            Object.assign(texto, { id: 'informe-comentario', maxLength: COMENTARIO_MAXIMO, rows: 3 });
            texto.placeholder = 'Ej.: Las Air Max 1 x Patta llevan dos meses paradas; ¿las rebajamos o las devolvemos?';
            const cuenta = crear('span', 'envio__cuenta num', `0 / ${COMENTARIO_MAXIMO}`);
            texto.addEventListener('input', () => {
                cuenta.textContent = `${texto.value.length} / ${COMENTARIO_MAXIMO}`;
            });
            const aviso = crear('p', 'paso__error');
            aviso.setAttribute('role', 'alert');
            const enviar = crear('button', 'control control--primario', 'Enviar a administración');
            enviar.type = 'submit';
            const pie = crear('div', 'envio__pie');
            pie.append(cuenta, enviar);
            envio.append(etiqueta, texto, aviso, pie);

            envio.addEventListener('submit', async (evento) => {
                evento.preventDefault();
                enviar.disabled = true;
                aviso.textContent = '';
                try {
                    const cuerpo = { tipo: ultimo.tipo, comentario: texto.value.trim() };
                    if (ultimo.tipo === 'sin_ventas') {
                        cuerpo.dias = ultimo.parametros.dias;
                    }
                    const { datos } = await api('/api/gestion/informes-stock', { metodo: 'POST', cuerpo });
                    texto.value = '';
                    cuenta.textContent = `0 / ${COMENTARIO_MAXIMO}`;
                    ctx.anunciar(`Informe n.º ${datos.id} enviado a administración`);
                    ctx.avisarCambio('informes-stock');
                    lista.cargar();
                } catch (error) {
                    if (sesionCaducada(error)) {
                        return;
                    }
                    aviso.textContent = error.estado === 400
                        ? Object.values(error.detalles?.errores ?? {}).join(' ') || error.message
                        : textoError(error);
                } finally {
                    enviar.disabled = false;
                }
            });
            return envio;
        }

        return {
            elemento,
            aplicar(anteriores) {
                pintarControles();
                if (!anteriores || anteriores.tipo !== params.tipo || anteriores.dias !== params.dias) {
                    cargar();
                }
            },
            abortar: () => control?.abort(),
        };
    }

    // ==========================================================================
    // Lista
    // ==========================================================================

    function crearLista() {
        const elemento = crear('section', 'tarjeta tarjeta--lista');
        const cabeza = crear('div', 'tarjeta__cabeza');
        const titulo = crear('h2', 'tarjeta__titulo', revisa ? 'Bandeja de informes' : 'Mis informes enviados');
        const meta = crear('p', 'tarjeta__meta', revisa ? 'Los pendientes primero' : '');
        cabeza.append(titulo, meta);

        // Pestañas por estado (como en Pedidos)
        const pestanas = crear('nav', 'pestanas');
        pestanas.setAttribute('aria-label', 'Filtrar por estado');
        const enlaces = new Map();
        for (const [estado, nombre] of [['', 'Todos'], ['pendiente', 'Pendientes'], ['revisado', 'Revisados']]) {
            const enlace = crear('a', 'pestana');
            enlace.append(crear('span', '', nombre), crear('span', 'pestana__cifra num', ''));
            pestanas.append(enlace);
            enlaces.set(estado, enlace);
        }
        const filtros = crear('div', 'filtros filtros--tarjeta');
        filtros.append(pestanas);

        const envoltorio = crear('div', 'tabla-desplazable');
        const tabla = crear('table', 'tabla tabla--analisis');
        tabla.innerHTML = '<caption class="oculto">Informes de stock enviados</caption><thead><tr>'
            + '<th scope="col">Informe</th><th scope="col">Enviado</th><th scope="col">Tipo</th>'
            + '<th scope="col" class="num">Filas</th><th scope="col">Comentario</th><th scope="col">Estado</th></tr></thead>';
        const cuerpo = crear('tbody');
        tabla.append(cuerpo);
        envoltorio.append(tabla);
        const aviso = crear('div', 'tarjeta__aviso');
        const paginas = crear('nav', 'paginas');
        paginas.setAttribute('aria-label', 'Páginas de la lista');
        elemento.append(cabeza, filtros, aviso, envoltorio, paginas);

        cuerpo.addEventListener('click', (evento) => {
            const fila = evento.target.closest('tr[data-id]');
            if (fila && !evento.target.closest('a')) {
                fila.querySelector('a')?.click();
            }
        });

        let control = null;

        async function cargar() {
            control?.abort();
            control = new AbortController();
            const senal = control.signal;
            for (const [estado, enlace] of enlaces) {
                enlace.href = hashDe('informes-stock', { ...params, estado, pagina: undefined, informe: undefined });
                if ((params.estado ?? '') === estado) {
                    enlace.setAttribute('aria-current', 'true');
                } else {
                    enlace.removeAttribute('aria-current');
                }
            }
            elemento.setAttribute('aria-busy', 'true');
            try {
                const filtro = new URLSearchParams({ por_pagina: POR_PAGINA, pagina: params.pagina ?? 1 });
                if (params.estado) {
                    filtro.set('estado', params.estado);
                }
                // Las cifras de las pestañas: una petición mínima por estado
                const [listado, ...cuentas] = await Promise.all([
                    api(`/api/gestion/informes-stock?${filtro}`, { senal }),
                    ...['', 'pendiente', 'revisado'].map((estado) => api(
                        `/api/gestion/informes-stock?por_pagina=1${estado ? '&estado=' + estado : ''}`, { senal },
                    )),
                ]);
                ['', 'pendiente', 'revisado'].forEach((estado, i) => {
                    enlaces.get(estado).lastChild.textContent = numero(cuentas[i].paginacion.total);
                });
                aviso.replaceChildren();
                pintarFilas(listado.datos);
                pintarPaginas(paginas, listado.paginacion, (pagina) => hashDe('informes-stock', { ...params, pagina, informe: undefined }));
                const pendientes = cuentas[1].paginacion.total;
                if (revisa) {
                    linea.textContent = pendientes === 0
                        ? 'No hay informes pendientes de revisar.'
                        : `${contar(pendientes, 'informe espera', 'informes esperan')} tu revisión.`;
                } else {
                    meta.textContent = pendientes === 0 ? 'Todos revisados' : `${contar(pendientes, 'pendiente', 'pendientes')} de revisar`;
                }
            } catch (error) {
                if (error.name === 'AbortError' || sesionCaducada(error)) {
                    return;
                }
                aviso.replaceChildren(bloqueError(textoError(error), () => cargar()));
            } finally {
                if (!senal.aborted) {
                    elemento.removeAttribute('aria-busy');
                }
            }
        }

        function pintarFilas(informes) {
            if (informes.length === 0) {
                const fila = crear('tr', 'tabla__vacia');
                const celda = crear('td', '', revisa ? 'No hay informes con este filtro.' : 'Todavía no has enviado ningún informe.');
                celda.colSpan = 6;
                fila.append(celda);
                cuerpo.replaceChildren(fila);
                return;
            }
            cuerpo.replaceChildren(...informes.map((informe) => {
                const fila = crear('tr');
                fila.dataset.id = String(informe.id);
                if (String(informe.id) === params.informe) {
                    fila.classList.add('tabla__fila--abierta');
                }
                const celdaId = crear('td');
                const enlace = crear('a', 'tabla__enlace', `N.º ${informe.id}`);
                enlace.href = hashDe('informes-stock', { ...params, informe: informe.id });
                celdaId.append(enlace);
                const enviado = crear('td');
                enviado.append(crear('span', '', fechaCorta(informe.creado_en)));
                if (revisa) {
                    enviado.append(crear('span', 'tabla__sub', informe.creado_por?.nombre ?? 'Usuario borrado'));
                }
                const tipo = crear('td');
                tipo.append(crear('span', '', NOMBRE_TIPO[informe.tipo] ?? informe.tipo), crear('span', 'tabla__sub', textoParametros(informe)));
                const estado = crear('td');
                estado.append(etiquetaEstadoInforme(informe));
                if (informe.revisado_en) {
                    estado.append(crear('span', 'tabla__sub', fechaCorta(informe.revisado_en)));
                }
                fila.append(
                    celdaId, enviado, tipo,
                    crear('td', 'num', numero(informe.total_filas)),
                    crear('td', 'tabla__corta', informe.comentario ?? '—'),
                    estado,
                );
                return fila;
            }));
        }

        return { elemento, cuerpo, cargar, abortar: () => control?.abort() };
    }

    // ==========================================================================
    // Detalle en el panel lateral
    // ==========================================================================

    let controlDetalle = null;

    async function abrirDetalle(id) {
        controlDetalle?.abort();
        controlDetalle = new AbortController();
        const senal = controlDetalle.signal;
        panel.abrir();
        panel.caja.replaceChildren(cabezaLateral(panel, `Informe n.º ${id}`), crear('p', 'detalle__cargando', 'Cargando el informe…'));
        for (const fila of lista.cuerpo.querySelectorAll('tr[data-id]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.id === String(id));
        }
        try {
            const { datos } = await api(`/api/gestion/informes-stock/${encodeURIComponent(id)}`, { senal });
            pintarDetalle(datos);
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            const mensaje = error.estado === 404 ? `No existe el informe n.º ${id}.` : textoError(error);
            panel.caja.replaceChildren(cabezaLateral(panel, `Informe n.º ${id}`), bloqueError(mensaje, error.estado === 404 ? null : () => abrirDetalle(id)));
        }
    }

    function pintarDetalle(informe) {
        const enviadoPor = informe.creado_por?.nombre ?? 'un usuario borrado';
        const cabeza = cabezaLateral(panel, `Informe n.º ${informe.id}`, {
            etiquetas: [etiquetaEstadoInforme(informe)],
            meta: `${NOMBRE_TIPO[informe.tipo]} · ${textoParametros(informe)} · enviado por ${enviadoPor} el ${fechaCorta(informe.creado_en)}`,
            textoCerrar: 'Cerrar el informe',
        });

        const acciones = crear('section', 'detalle__acciones');
        const aviso = crear('p', 'paso__error');
        aviso.setAttribute('role', 'alert');
        const botones = crear('div', 'detalle__botones');
        if (revisa && informe.estado === 'pendiente') {
            const marcar = crear('button', 'control control--primario', 'Marcar como revisado');
            marcar.type = 'button';
            marcar.addEventListener('click', async () => {
                marcar.disabled = true;
                try {
                    const { datos } = await api(`/api/gestion/informes-stock/${informe.id}/revisado`, { metodo: 'PATCH' });
                    pintarDetalle(datos);
                    panel.caja.querySelector('#analisis-lateral-titulo')?.focus();
                    ctx.anunciar(`Informe n.º ${datos.id} revisado`);
                    ctx.avisarCambio('informes-stock');
                    lista.cargar();
                } catch (error) {
                    if (sesionCaducada(error)) {
                        return;
                    }
                    aviso.textContent = textoError(error);
                    marcar.disabled = false;
                }
            });
            botones.append(marcar);
        }
        const csv = crear('a', 'control', 'Descargar CSV');
        csv.href = `/api/gestion/informes-stock/${informe.id}/csv`;
        csv.setAttribute('download', '');
        botones.append(csv);
        acciones.append(botones, aviso);
        if (informe.estado === 'revisado') {
            acciones.append(crear('p', 'detalle__sin-pasos',
                `Revisado por ${informe.revisado_por?.nombre ?? 'un usuario borrado'} el ${fechaCorta(informe.revisado_en)}.`));
        }

        const bloques = [cabeza, acciones];
        if (informe.comentario) {
            const comentario = crear('section', 'detalle__bloque');
            comentario.append(crear('h3', 'detalle__subtitulo', 'Comentario del almacén'), crear('blockquote', 'comentario', informe.comentario));
            bloques.push(comentario);
        }
        const foto = crear('section', 'detalle__bloque');
        foto.append(
            crear('h3', 'detalle__subtitulo', `Lo que había al enviarlo: ${resumenInforme(informe)}`),
            ...contenidoInforme(informe),
        );
        bloques.push(foto);
        panel.caja.replaceChildren(...bloques);
    }

    // ==========================================================================

    /** Cambió el hash: solo se vuelve a pedir lo que depende de lo que ha cambiado */
    function aplicar(nuevos) {
        const anteriores = params;
        params = limpiar(nuevos, revisa);
        generador?.aplicar(anteriores);
        if (anteriores.estado !== params.estado || anteriores.pagina !== params.pagina) {
            lista.cargar();
        }
        if (params.informe) {
            abrirDetalle(params.informe);
        } else if (panel.elemento.open) {
            panel.cerrar();
        }
    }

    // Primera carga
    generador?.aplicar(null);
    lista.cargar();
    if (params.informe) {
        abrirDetalle(params.informe);
    }

    // Otra sección (o este mismo envío) cambia los informes: la bandeja se refresca
    document.addEventListener('panel:cambio', (evento) => {
        if (evento.detail?.origen !== 'informes-stock') {
            lista.cargar();
        }
    }, { signal: ctx.senal });
    ctx.senal.addEventListener('abort', () => {
        generador?.abortar();
        lista.abortar();
        controlDetalle?.abort();
    });

    return { actualizar: aplicar };
}

// =============================================================================

/** Solo valores válidos del hash; el almacén tiene además tipo y días */
function limpiar(entrada, revisa) {
    const params = {
        estado: ['pendiente', 'revisado'].includes(entrada.estado) ? entrada.estado : undefined,
        pagina: /^[1-9]\d{0,4}$/.test(entrada.pagina ?? '') ? entrada.pagina : undefined,
        informe: /^[1-9]\d{0,17}$/.test(entrada.informe ?? '') ? entrada.informe : undefined,
    };
    if (!revisa) {
        const dias = Number(entrada.dias);
        params.tipo = entrada.tipo === 'sin_ventas' ? 'sin_ventas' : 'stock_bajo';
        params.dias = Number.isInteger(dias) && dias >= 1 && dias <= 365 ? dias : DIAS_POR_DEFECTO;
    }
    return params;
}

function consulta({ tipo, dias }) {
    return new URLSearchParams(tipo === 'sin_ventas' ? { tipo, dias } : { tipo }).toString();
}

function textoParametros(informe) {
    return informe.tipo === 'sin_ventas'
        ? `más de ${contar(informe.parametros.dias, 'día', 'días')} sin vender`
        : 'en el mínimo o por debajo';
}

function etiquetaEstadoInforme(informe) {
    return informe.estado === 'pendiente'
        ? crear('span', 'estado estado--pendiente', 'Pendiente')
        : crear('span', 'estado estado--revisado', 'Revisado');
}

/** «51 tallas en su mínimo o por debajo» / «13 zapatillas sin vender en 60 días» */
function resumenInforme(informe) {
    return informe.tipo === 'stock_bajo'
        ? `${contar(informe.total_filas, 'talla', 'tallas')} en su mínimo o por debajo`
        : `${contar(informe.total_filas, 'zapatilla', 'zapatillas')} sin vender en ${contar(informe.parametros.dias, 'día', 'días')}`;
}

/**
 * Gráfica sencilla + tabla completa de un informe (vista previa o foto
 * guardada). La gráfica responde a la pregunta de cada informe:
 *   - poco stock: qué modelos acumulan más tallas por reponer;
 *   - sin ventas: dónde está el stock parado (pares sin vender por modelo).
 */
function contenidoInforme(informe) {
    const piezas = [];
    if (informe.recortado) {
        piezas.push(crear('p', 'nota-recorte',
            `Se guardaron las primeras ${numero(informe.filas.length)} filas de ${numero(informe.total_filas)} (las más urgentes).`));
    }
    if (informe.filas.length === 0) {
        piezas.push(crear('p', 'vacio', informe.tipo === 'stock_bajo'
            ? 'Ninguna talla está en su mínimo: no hay nada que reponer.'
            : 'Todas las zapatillas a la venta se han vendido en este periodo.'));
        return piezas;
    }
    piezas.push(informe.tipo === 'stock_bajo' ? graficaStockBajo(informe.filas) : graficaSinVentas(informe));
    piezas.push(tablaFilas(informe));
    return piezas;
}

function graficaStockBajo(filas) {
    // Tallas por reponer de cada modelo (producto = modelo + colorway)
    const porModelo = new Map();
    for (const f of filas) {
        const modelo = porModelo.get(f.producto_id) ?? { ...f, tallas: 0, agotadas: 0 };
        modelo.tallas += 1;
        modelo.agotadas += f.stock === 0 ? 1 : 0;
        porModelo.set(f.producto_id, modelo);
    }
    const modelos = [...porModelo.values()].sort((a, b) => b.tallas - a.tallas || b.agotadas - a.agotadas || a.nombre.localeCompare(b.nombre));
    const primero = modelos[0];
    const primeros = modelos.slice(0, EN_GRAFICA);
    return barras({
        titulo: `${primero.nombre} es el que más tallas pide reponer: ${contar(primero.tallas, 'talla', 'tallas')}`,
        subtitulo: `Tallas en el mínimo o por debajo, por modelo${modelos.length > EN_GRAFICA ? ` (los ${EN_GRAFICA} primeros de ${modelos.length})` : ''}`,
        filas: primeros,
        valor: (m) => m.tallas,
        etiqueta: (m) => m.nombre,
        sub: (m) => m.colorway,
        formato: (n) => contar(n, 'talla', 'tallas'),
        detalle: (m) => (m.agotadas > 0 ? `${contar(m.agotadas, 'agotada', 'agotadas')} · ${m.codigo_marca}` : m.codigo_marca),
        destacar: (m, i) => i === 0,
    });
}

function graficaSinVentas(informe) {
    const filas = [...informe.filas].sort((a, b) => b.stock_total - a.stock_total);
    const primeros = filas.slice(0, EN_GRAFICA);
    const total = filas.reduce((s, f) => s + f.stock_total, 0);
    const primero = primeros[0];
    return barras({
        titulo: `${contar(total, 'par parado', 'pares parados')}; ${primero.nombre} acumula ${numero(primero.stock_total)}`,
        subtitulo: `Stock de las zapatillas sin vender en ${contar(informe.parametros.dias, 'día', 'días')}${filas.length > EN_GRAFICA ? ` (las ${EN_GRAFICA} con más stock de ${filas.length})` : ''}`,
        filas: primeros,
        valor: (f) => f.stock_total,
        etiqueta: (f) => f.nombre,
        sub: (f) => f.colorway,
        formato: (n) => contar(n, 'par', 'pares'),
        detalle: (f) => (f.ultima_venta ? `Última venta hace ${contar(f.dias_sin_vender, 'día', 'días')}` : 'Nunca se ha vendido'),
        destacar: (f, i) => i === 0,
    });
}

function tablaFilas(informe) {
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla');
    const cuerpo = crear('tbody');
    if (informe.tipo === 'stock_bajo') {
        tabla.innerHTML = '<caption class="oculto">Tallas con poco stock</caption><thead><tr><th scope="col">Modelo</th>'
            + '<th scope="col">Código</th><th scope="col" class="num">Talla</th><th scope="col" class="num">Stock</th>'
            + '<th scope="col" class="num">Mínimo</th></tr></thead>';
        for (const f of informe.filas) {
            const fila = crear('tr');
            const modelo = crear('td');
            modelo.append(crear('span', '', f.nombre), crear('span', 'tabla__sub', f.colorway));
            const stock = crear('td', 'num');
            stock.append(crear('span', f.stock === 0 ? 'alerta' : '', f.stock === 0 ? 'Agotada' : numero(f.stock)));
            fila.append(modelo, crear('td', 'tabla__codigo', f.codigo_marca), crear('td', 'num', talla(f.talla)), stock, crear('td', 'num', numero(f.stock_minimo)));
            cuerpo.append(fila);
        }
    } else {
        tabla.innerHTML = '<caption class="oculto">Zapatillas sin ventas</caption><thead><tr><th scope="col">Modelo</th>'
            + '<th scope="col">Código</th><th scope="col" class="num">Stock</th><th scope="col">Última venta</th></tr></thead>';
        for (const f of informe.filas) {
            const fila = crear('tr');
            const modelo = crear('td');
            modelo.append(crear('span', '', f.nombre), crear('span', 'tabla__sub', f.colorway));
            const venta = crear('td');
            if (f.ultima_venta) {
                venta.append(crear('span', '', fechaCorta(f.ultima_venta)), crear('span', 'tabla__sub', `hace ${contar(f.dias_sin_vender, 'día', 'días')}`));
            } else {
                venta.append(crear('span', 'tabla__de', 'Nunca'));
            }
            fila.append(modelo, crear('td', 'tabla__codigo', f.codigo_marca),
                crear('td', 'num', `${numero(f.stock_total)} en ${contar(f.tallas, 'talla', 'tallas')}`), venta);
            cuerpo.append(fila);
        }
    }
    tabla.append(cuerpo);
    envoltorio.append(tabla);
    return envoltorio;
}
