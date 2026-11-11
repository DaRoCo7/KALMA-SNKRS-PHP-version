/*
 * Sección «Pedidos»: lista de todos los pedidos con filtros y paginación, y el
 * detalle de uno en un panel lateral desde el que se cambia su estado.
 *
 * API: GET /api/gestion/pedidos?estado&buscar&personalizado&pagina&por_pagina,
 * GET /api/gestion/pedidos/{codigo} y PATCH /api/gestion/pedidos/{codigo}/estado.
 *
 * Los filtros y el pedido abierto viven en el hash (#pedidos?estado=pendiente
 * &pedido=SF-2026-000021): recargar la página o volver atrás deja todo igual.
 * Los botones de estado salen de ctx.transiciones, que el servidor calcula con
 * las mismas reglas que aplica la API (almacén no confirma ni cancela).
 */

import { api, cerrarDialogo, crear, precio, prepararDialogo, talla } from '../api.js';
import {
    ESTADOS, NOMBRE_ESTADO, bloqueError, cabeceraSeccion, contar, etiquetaEstado,
    fechaCorta, hashDe, numero, sesionCaducada, textoError,
} from './comun.js';

const POR_PAGINA = 20;
const ESPERA_BUSQUEDA = 300;   // ms: no se pide la lista en cada tecla

/** Texto del botón de cada destino: dice lo que pasa en la tienda, no el nombre técnico */
const ACCIONES = {
    confirmado: 'Confirmar pago',
    preparacion: 'Pasar a preparación',
    enviado: 'Marcar como enviado',
    entregado: 'Marcar como entregado',
    cancelado: 'Cancelar pedido',
};

/** Lo que se anuncia tras cada cambio */
const HECHO = {
    confirmado: 'pago confirmado',
    preparacion: 'en preparación',
    enviado: 'enviado',
    entregado: 'entregado',
    cancelado: 'cancelado y stock repuesto',
};

const TIPOS_PERSONALIZACION = {
    packaging: 'Caja',
    bordado: 'Bordado',
    cordones: 'Cordones',
    certificado: 'Certificado',
};

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let controlLista = null;
    let controlDetalle = null;
    let temporizador = 0;
    let pedidoAbierto = null;     // detalle que se está mostrando
    let primeraLista = true;

    // --- Estructura ------------------------------------------------------------

    const { cabecera, linea } = cabeceraSeccion('Pedidos');
    linea.textContent = 'Cargando los pedidos…';

    const barra = crear('div', 'filtros');
    const pestanas = crear('nav', 'pestanas');
    pestanas.setAttribute('aria-label', 'Filtrar por estado');
    const enlacesEstado = new Map();
    for (const estado of ['', ...ESTADOS]) {
        const enlace = crear('a', 'pestana');
        enlace.append(crear('span', '', estado ? NOMBRE_ESTADO[estado] : 'Todos'), crear('span', 'pestana__cifra num', ''));
        enlace.dataset.estado = estado;
        pestanas.append(enlace);
        enlacesEstado.set(estado, enlace);
    }

    const busqueda = crear('form', 'filtros__busqueda');
    busqueda.setAttribute('role', 'search');
    const etiquetaBuscar = crear('label', 'oculto', 'Buscar por código de pedido o email');
    etiquetaBuscar.htmlFor = 'pedidos-buscar';
    const campoBuscar = crear('input', 'campo-texto');
    Object.assign(campoBuscar, {
        id: 'pedidos-buscar', type: 'search', maxLength: 60, placeholder: 'Código o email', autocomplete: 'off',
    });
    const etiquetaPersonalizado = crear('label', 'casilla');
    const casilla = crear('input');
    casilla.type = 'checkbox';
    etiquetaPersonalizado.append(casilla, crear('span', '', 'Solo personalizados'));
    busqueda.append(etiquetaBuscar, campoBuscar, etiquetaPersonalizado);
    barra.append(pestanas, busqueda);

    const bloqueLista = crear('section', 'tarjeta tarjeta--lista');
    bloqueLista.setAttribute('aria-labelledby', 'panel-titulo');
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla tabla--pedidos');
    tabla.innerHTML = '<caption class="oculto">Pedidos, del más reciente al más antiguo</caption>'
        + '<thead><tr><th scope="col">Pedido</th><th scope="col">Fecha</th><th scope="col">Cliente</th>'
        + '<th scope="col">Estado</th><th scope="col" class="num">Pares</th><th scope="col" class="num">Importe</th></tr></thead>';
    const cuerpoTabla = crear('tbody');
    tabla.append(cuerpoTabla);
    envoltorio.append(tabla);
    const avisoLista = crear('div', 'tarjeta__aviso');
    const paginas = crear('nav', 'paginas');
    paginas.setAttribute('aria-label', 'Páginas de la lista');
    bloqueLista.append(avisoLista, envoltorio, paginas);

    const dialogo = crearDialogo();
    contenedor.append(cabecera, barra, bloqueLista, dialogo.elemento);

    // --- Filtros -----------------------------------------------------------------

    function irConFiltros(cambios, opciones = { reemplazar: true }) {
        ctx.irA('pedidos', { ...params, pagina: undefined, ...cambios }, opciones);
    }

    busqueda.addEventListener('submit', (evento) => {
        evento.preventDefault();
        window.clearTimeout(temporizador);
        irConFiltros({ buscar: campoBuscar.value.trim(), pedido: undefined });
    });
    campoBuscar.addEventListener('input', () => {
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(
            () => irConFiltros({ buscar: campoBuscar.value.trim(), pedido: undefined }),
            ESPERA_BUSQUEDA
        );
    });
    casilla.addEventListener('change', () => irConFiltros({ personalizado: casilla.checked ? '1' : undefined, pedido: undefined }));

    // Toda la fila abre el pedido, no solo el código (que es el enlace accesible)
    cuerpoTabla.addEventListener('click', (evento) => {
        const fila = evento.target.closest('tr[data-codigo]');
        if (fila && !evento.target.closest('a')) {
            fila.querySelector('a')?.click();
        }
    });

    function pintarFiltros() {
        for (const [estado, enlace] of enlacesEstado) {
            enlace.href = hashDe('pedidos', { ...params, estado, pagina: undefined, pedido: undefined });
            if ((params.estado ?? '') === estado) {
                enlace.setAttribute('aria-current', 'true');
            } else {
                enlace.removeAttribute('aria-current');
            }
        }
        if (document.activeElement !== campoBuscar) {
            campoBuscar.value = params.buscar ?? '';
        }
        casilla.checked = params.personalizado === '1';
    }

    // --- Lista -------------------------------------------------------------------

    function consulta(extra) {
        const datos = { buscar: params.buscar, personalizado: params.personalizado, ...extra };
        return new URLSearchParams(Object.entries(datos).filter(([, v]) => v !== undefined && v !== '')).toString();
    }

    async function cargarLista() {
        controlLista?.abort();
        controlLista = new AbortController();
        const senal = controlLista.signal;
        bloqueLista.setAttribute('aria-busy', 'true');
        if (primeraLista) {
            cuerpoTabla.replaceChildren(...filasEsqueleto());
        }

        try {
            // La lista y, a la vez, cuántos hay en cada estado con los mismos filtros:
            // las cifras de las pestañas siguen a la búsqueda
            const [lista, ...cuentas] = await Promise.all([
                api(`/api/gestion/pedidos?${consulta({ estado: params.estado, pagina: params.pagina ?? 1, por_pagina: POR_PAGINA })}`, { senal }),
                ...['', ...ESTADOS].map((estado) => api(`/api/gestion/pedidos?${consulta({ estado, por_pagina: 1 })}`, { senal })),
            ]);
            primeraLista = false;
            avisoLista.replaceChildren();
            pintarCuentas(cuentas.map((c) => c.paginacion.total));
            pintarFilas(lista.datos);
            pintarPaginas(lista.paginacion);
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            if (primeraLista) {
                cuerpoTabla.replaceChildren();
            }
            linea.textContent = 'No se han podido cargar los pedidos.';
            avisoLista.replaceChildren(bloqueError(textoError(error), () => cargarLista()));
        } finally {
            if (!senal.aborted) {
                bloqueLista.removeAttribute('aria-busy');
            }
        }
    }

    function pintarCuentas(totales) {
        ['', ...ESTADOS].forEach((estado, i) => {
            enlacesEstado.get(estado).querySelector('.pestana__cifra').textContent = numero(totales[i]);
        });
        const [todos, pendientes, confirmados] = totales;
        // La cola de cada rol: administración confirma pagos, almacén prepara lo confirmado
        const cola = ctx.puede('pedidos.confirmar_cancelar')
            ? `${contar(pendientes, 'espera', 'esperan')} la confirmación del pago`
            : `${contar(confirmados, 'confirmado espera', 'confirmados esperan')} a prepararse`;
        const filtrado = params.buscar || params.personalizado ? ' con estos filtros' : '';
        linea.textContent = `${contar(todos, 'pedido', 'pedidos')}${filtrado} · ${cola}`;
    }

    function pintarFilas(pedidos) {
        if (pedidos.length === 0) {
            const fila = crear('tr', 'tabla__vacia');
            const celda = crear('td');
            celda.colSpan = 6;
            celda.append(crear('span', '', 'No hay pedidos con estos filtros. '));
            if (params.estado || params.buscar || params.personalizado) {
                const quitar = crear('a', 'tabla__enlace', 'Quitar los filtros');
                quitar.href = hashDe('pedidos');
                celda.append(quitar);
            }
            fila.append(celda);
            cuerpoTabla.replaceChildren(fila);
            return;
        }

        cuerpoTabla.replaceChildren(...pedidos.map((pedido) => {
            const fila = crear('tr');
            fila.dataset.codigo = pedido.codigo;
            if (pedido.codigo === params.pedido) {
                fila.classList.add('tabla__fila--abierta');
            }

            const codigo = crear('td');
            const enlace = crear('a', 'tabla__enlace', pedido.codigo);
            enlace.href = hashDe('pedidos', { ...params, pedido: pedido.codigo });
            codigo.append(enlace);
            if (pedido.personalizado) {
                codigo.append(crear('span', 'tabla__sub', 'Personalizado'));
            }

            const cliente = crear('td', 'tabla__cliente');
            cliente.append(crear('span', '', pedido.cliente.nombre), crear('span', 'tabla__sub', pedido.cliente.email));

            const estado = crear('td');
            estado.append(etiquetaEstado(pedido.estado));

            fila.append(
                codigo,
                crear('td', 'tabla__fecha num', fechaCorta(pedido.fecha_creacion)),
                cliente,
                estado,
                crear('td', 'num', numero(pedido.unidades)),
                crear('td', 'num', precio(pedido.total)),
            );
            return fila;
        }));
    }

    function pintarPaginas({ pagina, por_pagina: porPagina, total, paginas: numPaginas }) {
        paginas.replaceChildren();
        if (total === 0) {
            return;
        }
        const desde = (pagina - 1) * porPagina + 1;
        const hasta = Math.min(pagina * porPagina, total);
        paginas.append(crear('p', 'paginas__rango num', `${numero(desde)}–${numero(hasta)} de ${numero(total)}`));

        const botones = crear('div', 'paginas__botones');
        for (const [texto, destino, activo] of [
            ['Anterior', pagina - 1, pagina > 1],
            ['Siguiente', pagina + 1, pagina < numPaginas],
        ]) {
            if (activo) {
                const enlace = crear('a', 'control', texto);
                enlace.href = hashDe('pedidos', { ...params, pagina: destino, pedido: undefined });
                botones.append(enlace);
            } else {
                // Sin enlace: un enlace desactivado seguiría recibiendo el foco
                const apagado = crear('span', 'control control--apagado', texto);
                apagado.setAttribute('aria-disabled', 'true');
                botones.append(apagado);
            }
        }
        paginas.append(botones);
    }

    // --- Detalle -------------------------------------------------------------------

    function crearDialogo() {
        const elemento = crear('dialog', 'detalle');
        elemento.setAttribute('aria-labelledby', 'detalle-titulo');
        const caja = crear('div', 'detalle__caja');
        elemento.append(caja);
        prepararDialogo(elemento);
        // Al cerrarse (Escape, el fondo o la X) se quita el pedido de la URL
        elemento.addEventListener('close', () => {
            pedidoAbierto = null;
            // Si se cierra porque se sale de la sección, la URL ya es la de la otra
            if (ctx.senal.aborted) {
                return;
            }
            controlDetalle?.abort();
            if (params.pedido) {
                irConFiltros({ pedido: undefined, pagina: params.pagina });
            }
            cuerpoTabla.querySelector('.tabla__fila--abierta')?.classList.remove('tabla__fila--abierta');
        });
        return { elemento, caja };
    }

    async function abrirDetalle(codigo) {
        controlDetalle?.abort();
        controlDetalle = new AbortController();
        const senal = controlDetalle.signal;
        const { elemento, caja } = dialogo;

        if (!elemento.open) {
            elemento.showModal();
        }
        if (pedidoAbierto?.codigo !== codigo) {
            caja.replaceChildren(cabezaDetalle(codigo, null), crear('p', 'detalle__cargando', 'Cargando el pedido…'));
        }
        for (const fila of cuerpoTabla.querySelectorAll('tr[data-codigo]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.codigo === codigo);
        }

        try {
            const { datos } = await api(`/api/gestion/pedidos/${encodeURIComponent(codigo)}`, { senal });
            pintarDetalle(datos);
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            const mensaje = error.estado === 404 ? `No existe el pedido ${codigo}.` : textoError(error);
            caja.replaceChildren(cabezaDetalle(codigo, null), bloqueError(mensaje, error.estado === 404 ? null : () => abrirDetalle(codigo)));
        }
    }

    function cabezaDetalle(codigo, pedido) {
        const cabeza = crear('header', 'detalle__cabeza');
        const titulo = crear('h2', 'detalle__titulo', codigo);
        titulo.id = 'detalle-titulo';
        titulo.tabIndex = -1;
        const fila = crear('div', 'detalle__fila-titulo');
        fila.append(titulo);
        if (pedido) {
            fila.append(etiquetaEstado(pedido.estado));
        }
        const cerrar = crear('button', 'control control--icono');
        cerrar.type = 'button';
        cerrar.setAttribute('aria-label', 'Cerrar el pedido');
        cerrar.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 3.5l9 9m0-9l-9 9" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>';
        cerrar.addEventListener('click', () => cerrarDialogo(dialogo.elemento));
        cabeza.append(fila, cerrar);
        if (pedido) {
            cabeza.append(crear('p', 'detalle__meta', `Creado el ${fechaCorta(pedido.fecha_creacion)} · ${contar(pedido.lineas.reduce((s, l) => s + l.cantidad, 0), 'par', 'pares')} · ${precio(pedido.total)}`));
        }
        return cabeza;
    }

    function pintarDetalle(pedido) {
        pedidoAbierto = pedido;
        const aviso = crear('div', 'detalle__aviso');
        aviso.setAttribute('role', 'alert');
        dialogo.caja.replaceChildren(
            cabezaDetalle(pedido.codigo, pedido),
            aviso,
            bloqueAcciones(pedido, aviso),
            bloqueEnvio(pedido),
            bloqueLineas(pedido),
            bloqueHistorial(pedido),
        );
    }

    /** Solo los pasos que este rol puede dar desde el estado actual */
    function bloqueAcciones(pedido, aviso) {
        const bloque = crear('section', 'detalle__acciones');
        bloque.setAttribute('aria-label', 'Cambiar el estado');
        const destinos = ctx.puede('pedidos.cambiar_estado') ? (ctx.transiciones[pedido.estado] ?? []) : [];

        if (destinos.length === 0) {
            let texto = 'No hay ningún paso que puedas dar en este pedido.';
            if (pedido.estado === 'entregado') {
                texto = 'Pedido entregado: no tiene más pasos.';
            } else if (pedido.estado === 'cancelado') {
                texto = 'Pedido cancelado: su stock ya se repuso.';
            } else if (pedido.estado === 'pendiente') {
                texto = 'Espera a que administración confirme el pago.';
            }
            bloque.append(crear('p', 'detalle__sin-pasos', texto));
            return bloque;
        }

        const botones = crear('div', 'detalle__botones');
        const formulario = crear('div', 'detalle__formulario');
        const avanzar = destinos.filter((d) => d !== 'cancelado');

        avanzar.forEach((destino, i) => {
            const boton = crear('button', i === 0 ? 'control control--primario' : 'control', ACCIONES[destino]);
            boton.type = 'button';
            boton.addEventListener('click', () => {
                if (destino === 'enviado') {
                    formulario.replaceChildren(formularioEnvio(pedido, aviso, botones));
                    formulario.querySelector('input')?.focus();
                } else {
                    cambiarEstado(pedido, { estado: destino }, aviso, botones);
                }
            });
            botones.append(boton);
        });

        if (destinos.includes('cancelado')) {
            const cancelar = crear('button', 'control control--peligro', ACCIONES.cancelado);
            cancelar.type = 'button';
            cancelar.addEventListener('click', () => {
                formulario.replaceChildren(confirmacionCancelar(pedido, aviso, botones, cancelar));
                formulario.querySelector('.control--peligro-lleno')?.focus();
            });
            botones.append(cancelar);
        }

        bloque.append(botones, formulario);
        return bloque;
    }

    /** Pasar a «enviado» exige el número de seguimiento del transportista */
    function formularioEnvio(pedido, aviso, botones) {
        const form = crear('form', 'paso');
        const id = 'detalle-seguimiento';
        const etiqueta = crear('label', 'paso__etiqueta', 'Código de seguimiento del transportista');
        etiqueta.htmlFor = id;
        const campo = crear('input', 'campo-texto');
        Object.assign(campo, { id, name: 'codigo_seguimiento', required: true, maxLength: 50, autocomplete: 'off', spellcheck: false });
        campo.setAttribute('autocapitalize', 'characters');
        const error = crear('p', 'paso__error');
        error.id = `${id}-error`;
        const fila = crear('div', 'paso__botones');
        const guardar = crear('button', 'control control--primario', 'Guardar envío');
        guardar.type = 'submit';
        const volver = crear('button', 'control control--texto', 'Volver');
        volver.type = 'button';
        volver.addEventListener('click', () => form.remove());
        fila.append(guardar, volver);
        form.append(etiqueta, campo, error, fila);
        form.addEventListener('submit', (evento) => {
            evento.preventDefault();
            cambiarEstado(pedido, { estado: 'enviado', codigo_seguimiento: campo.value.trim() }, aviso, botones, { campo, error });
        });
        return form;
    }

    /** Cancelar repone el stock y obliga a devolver el pago: se pide confirmación */
    function confirmacionCancelar(pedido, aviso, botones, botonCancelar) {
        const caja = crear('form', 'paso paso--peligro');
        const pares = pedido.lineas.reduce((s, l) => s + l.cantidad, 0);
        caja.append(crear('p', 'paso__texto', `¿Cancelar ${pedido.codigo}? Vuelven al stock ${contar(pares, 'par', 'pares')} y habrá que devolver ${precio(pedido.total)} al cliente.`));
        const id = 'detalle-motivo';
        const etiqueta = crear('label', 'paso__etiqueta', 'Motivo para el historial (opcional)');
        etiqueta.htmlFor = id;
        const motivo = crear('input', 'campo-texto');
        Object.assign(motivo, { id, maxLength: 255, autocomplete: 'off' });
        const error = crear('p', 'paso__error');
        error.id = `${id}-error`;
        const fila = crear('div', 'paso__botones');
        const si = crear('button', 'control control--peligro-lleno', 'Sí, cancelar el pedido');
        si.type = 'submit';
        const no = crear('button', 'control control--texto', 'No, mantenerlo');
        no.type = 'button';
        no.addEventListener('click', () => {
            caja.remove();
            botonCancelar.focus();
        });
        fila.append(si, no);
        caja.append(etiqueta, motivo, error, fila);
        caja.addEventListener('submit', (evento) => {
            evento.preventDefault();
            const cuerpo = { estado: 'cancelado' };
            if (motivo.value.trim()) {
                cuerpo.comentario = motivo.value.trim();
            }
            cambiarEstado(pedido, cuerpo, aviso, botones, { campo: motivo, error, nombreCampo: 'comentario' });
        });
        return caja;
    }

    async function cambiarEstado(pedido, cuerpo, aviso, botones, formulario = null) {
        const pulsables = dialogo.caja.querySelectorAll('.detalle__acciones button');
        pulsables.forEach((b) => { b.disabled = true; });
        botones.setAttribute('aria-busy', 'true');
        aviso.replaceChildren();

        try {
            const { datos } = await api(`/api/gestion/pedidos/${encodeURIComponent(pedido.codigo)}/estado`, { metodo: 'PATCH', cuerpo });
            pintarDetalle(datos);
            dialogo.caja.querySelector('#detalle-titulo')?.focus();
            ctx.anunciar(`${datos.codigo}: ${HECHO[datos.estado] ?? NOMBRE_ESTADO[datos.estado]}`);
            cargarLista();
            ctx.avisarCambio('pedidos');
        } catch (error) {
            if (sesionCaducada(error)) {
                return;
            }
            pulsables.forEach((b) => { b.disabled = false; });
            botones.removeAttribute('aria-busy');

            // 400: el motivo va junto al campo que lo causa, si está a la vista
            const errores = error.detalles?.errores ?? {};
            const nombreCampo = formulario?.nombreCampo ?? 'codigo_seguimiento';
            if (error.estado === 400 && formulario && errores[nombreCampo]) {
                formulario.error.textContent = errores[nombreCampo];
                formulario.campo.setAttribute('aria-invalid', 'true');
                formulario.campo.setAttribute('aria-describedby', formulario.error.id);
                formulario.campo.focus();
                return;
            }

            const lineas = [textoError(error), ...Object.values(errores)];
            aviso.replaceChildren(bloqueError(lineas.join(' ')));
            // 409: el pedido ha cambiado por otro lado; se vuelve a leer para enseñar el estado real
            if (error.estado === 409 || error.estado === 404) {
                try {
                    const { datos } = await api(`/api/gestion/pedidos/${encodeURIComponent(pedido.codigo)}`);
                    const avisoActual = aviso.cloneNode(true);
                    pintarDetalle(datos);
                    dialogo.caja.querySelector('.detalle__aviso').replaceWith(avisoActual);
                    cargarLista();
                } catch {
                    // Se queda el aviso: ya dice qué ha pasado
                }
            }
        }
    }

    // --- Arranque y cambios de la URL ----------------------------------------------

    function aplicarParams(nuevos) {
        const anteriores = params;
        params = limpiar(nuevos);
        pintarFiltros();

        const cambiaLista = ['estado', 'buscar', 'personalizado', 'pagina'].some((k) => anteriores[k] !== params[k]);
        if (cambiaLista) {
            cargarLista();
        }
        if (params.pedido && params.pedido !== pedidoAbierto?.codigo) {
            abrirDetalle(params.pedido);
        } else if (!params.pedido && dialogo.elemento.open) {
            cerrarDialogo(dialogo.elemento);
        }
    }

    // Un cambio hecho en otra sección también puede mover pedidos de estado
    document.addEventListener('panel:cambio', (evento) => {
        if (evento.detail?.origen !== 'pedidos') {
            cargarLista();
        }
    }, { signal: ctx.senal });
    ctx.senal.addEventListener('abort', () => {
        controlLista?.abort();
        controlDetalle?.abort();
        window.clearTimeout(temporizador);
        if (dialogo.elemento.open) {
            dialogo.elemento.close();
        }
    });

    pintarFiltros();
    cargarLista();
    if (params.pedido) {
        abrirDetalle(params.pedido);
    }

    return { actualizar: aplicarParams };
}

/** Solo los parámetros que entiende la sección, sin valores vacíos */
function limpiar(entrada) {
    const salida = {};
    if (ESTADOS.includes(entrada.estado)) {
        salida.estado = entrada.estado;
    }
    if (entrada.buscar) {
        salida.buscar = String(entrada.buscar).slice(0, 60);
    }
    if (entrada.personalizado === '1') {
        salida.personalizado = '1';
    }
    const pagina = Number.parseInt(entrada.pagina, 10);
    if (pagina > 1) {
        salida.pagina = String(pagina);
    }
    if (entrada.pedido) {
        salida.pedido = String(entrada.pedido);
    }
    return salida;
}

function filasEsqueleto() {
    return Array.from({ length: 6 }, () => {
        const fila = crear('tr', 'tabla__esqueleto');
        for (let i = 0; i < 6; i++) {
            const celda = crear('td');
            celda.append(crear('span', 'hueco'));
            fila.append(celda);
        }
        return fila;
    });
}

function bloqueEnvio(pedido) {
    const bloque = crear('section', 'detalle__bloque');
    bloque.append(crear('h3', 'detalle__subtitulo', 'Cliente y envío'));
    const lista = crear('dl', 'datos');
    const filas = [
        ['Cliente', pedido.cliente.nombre],
        ['Email', pedido.cliente.email],
        ['Dirección', pedido.direccion_envio],
        ['Seguimiento', pedido.codigo_seguimiento],
        ['Notas', pedido.notas],
    ];
    for (const [nombre, valor] of filas) {
        lista.append(crear('dt', '', nombre), crear('dd', valor ? '' : 'datos__vacio', valor || '—'));
    }
    bloque.append(lista);
    return bloque;
}

function bloqueLineas(pedido) {
    const bloque = crear('section', 'detalle__bloque');
    bloque.append(crear('h3', 'detalle__subtitulo', `Zapatillas (${contar(pedido.lineas.length, 'línea', 'líneas')})`));
    const lista = crear('ul', 'lineas');
    for (const linea of pedido.lineas) {
        const elemento = crear('li', 'linea');
        const foto = crear('img', 'linea__foto');
        Object.assign(foto, { src: linea.imagen, alt: '', width: 56, height: 56, loading: 'lazy', decoding: 'async' });
        foto.addEventListener('error', () => foto.classList.add('linea__foto--sin'), { once: true });

        const textos = crear('div', 'linea__textos');
        textos.append(
            crear('p', 'linea__nombre', linea.producto),
            crear('p', 'linea__meta', `${linea.color} · talla ${talla(linea.talla)}`),
            crear('p', 'linea__meta num', `${contar(linea.cantidad, 'par', 'pares')} × ${precio(linea.precio_unitario)}`
                + (linea.recargo_personalizacion > 0 ? ` + ${precio(linea.recargo_personalizacion)} de personalización por par` : '')),
        );
        const opciones = linea.personalizacion ?? [];
        if (opciones.length > 0) {
            const lista = crear('ul', 'linea__personalizacion');
            for (const opcion of opciones) {
                const item = crear('li');
                item.append(crear('span', 'linea__tipo', TIPOS_PERSONALIZACION[opcion.tipo] ?? opcion.tipo));
                const texto = opcion.texto ? `${opcion.nombre}: «${opcion.texto}»` : opcion.nombre;
                item.append(crear('span', '', texto));
                lista.append(item);
            }
            textos.append(lista);
        }
        elemento.append(foto, textos, crear('p', 'linea__importe num', precio(linea.importe)));
        lista.append(elemento);
    }
    const total = crear('p', 'lineas__total');
    total.append(crear('span', '', 'Total'), crear('span', 'num', precio(pedido.total)));
    bloque.append(lista, total);
    return bloque;
}

function bloqueHistorial(pedido) {
    const bloque = crear('section', 'detalle__bloque');
    bloque.append(crear('h3', 'detalle__subtitulo', 'Historial de estados'));
    const lista = crear('ol', 'historial');
    const NOMBRES_ROL = { admin: 'administración', almacen: 'almacén', cliente: 'cliente' };
    for (const paso of pedido.historial) {
        const item = crear('li', 'historial__paso');
        const cabeza = crear('p', 'historial__cabeza');
        cabeza.append(etiquetaEstado(paso.estado_nuevo), crear('span', 'historial__fecha num', fechaCorta(paso.fecha)));
        const quien = [paso.usuario, paso.rol ? `(${NOMBRES_ROL[paso.rol] ?? paso.rol})` : null].filter(Boolean).join(' ');
        item.append(cabeza);
        if (paso.comentario) {
            item.append(crear('p', 'historial__comentario', paso.comentario));
        }
        if (quien) {
            item.append(crear('p', 'historial__quien', quien));
        }
        lista.append(item);
    }
    bloque.append(lista);
    return bloque;
}
