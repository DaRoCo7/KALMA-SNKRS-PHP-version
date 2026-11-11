/*
 * Detalle de un pedido en el panel lateral de la sección «Pedidos»: cliente y
 * envío, líneas con su personalización, historial y los botones de estado.
 *
 * API: GET /api/gestion/pedidos/{codigo} y PATCH /api/gestion/pedidos/{codigo}/estado.
 *
 * Va aparte de la lista (pedidos.js) porque es la mitad de la sección y solo
 * se comunica con ella por dos avisos: alCerrar (quitar el pedido de la URL)
 * y alCambiar (recargar la lista tras un cambio de estado). Los botones salen
 * de ctx.transiciones, que el servidor calcula con las mismas reglas que
 * aplica la API (almacén no confirma ni cancela).
 */

import { api, crear, precio, talla } from '../api.js';
import { NOMBRE_ESTADO, bloqueError, contar, etiquetaEstado, fechaCorta, sesionCaducada, textoError } from './comun.js';
import { cabezaLateral, lateral } from './piezas.js';

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

const NOMBRES_ROL = { admin: 'administración', almacen: 'almacén', cliente: 'cliente' };

/**
 * @param ctx        contexto de la sección (panel.js)
 * @param alCerrar   se ha cerrado por Escape, el fondo o la X
 * @param alCambiar  un pedido ha cambiado de estado (o se ha visto que había cambiado)
 */
export function crearDetallePedido(ctx, { alCerrar, alCambiar }) {
    let control = null;
    // Código del pedido abierto (o que se está cargando). Se pone al abrir y se
    // quita al cerrar: lo que llegue después de cerrar ya no tiene dónde pintarse
    let codigoAbierto = null;

    const panel = lateral(ctx, () => {
        codigoAbierto = null;
        control?.abort();
        alCerrar();
    }, { idTitulo: 'detalle-titulo' });
    ctx.senal.addEventListener('abort', () => control?.abort());

    /** ¿Sigue a la vista este pedido? Si se ha cerrado (o se está cerrando) o se ha abierto otro, no */
    function sigueAbierto(codigo) {
        return panel.visible() && codigoAbierto === codigo;
    }

    async function abrir(codigo) {
        control?.abort();
        control = new AbortController();
        const senal = control.signal;
        const otro = codigoAbierto !== codigo;
        codigoAbierto = codigo;

        panel.abrir();
        if (otro) {
            panel.caja.replaceChildren(cabeza(codigo, null), crear('p', 'detalle__cargando', 'Cargando el pedido…'));
        }

        try {
            const { datos } = await api(`/api/gestion/pedidos/${encodeURIComponent(codigo)}`, { senal });
            pintar(datos);
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            const mensaje = error.estado === 404 ? `No existe el pedido ${codigo}.` : textoError(error);
            panel.caja.replaceChildren(cabeza(codigo, null), bloqueError(mensaje, error.estado === 404 ? null : () => abrir(codigo)));
        }
    }

    function cabeza(codigo, pedido) {
        return cabezaLateral(panel, codigo, {
            etiquetas: pedido ? [etiquetaEstado(pedido.estado)] : [],
            meta: pedido
                ? `Creado el ${fechaCorta(pedido.fecha_creacion)} · ${contar(pares(pedido), 'par', 'pares')} · ${precio(pedido.total)}`
                : '',
            textoCerrar: 'Cerrar el pedido',
        });
    }

    function pintar(pedido) {
        const aviso = crear('div', 'detalle__aviso');
        aviso.setAttribute('role', 'alert');
        panel.caja.replaceChildren(
            cabeza(pedido.codigo, pedido),
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
        caja.append(crear('p', 'paso__texto', `¿Cancelar ${pedido.codigo}? Vuelven al stock ${contar(pares(pedido), 'par', 'pares')} y habrá que devolver ${precio(pedido.total)} al cliente.`));
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
        const pulsables = panel.caja.querySelectorAll('.detalle__acciones button');
        pulsables.forEach((b) => { b.disabled = true; });
        botones.setAttribute('aria-busy', 'true');
        aviso.replaceChildren();

        try {
            const { datos } = await api(`/api/gestion/pedidos/${encodeURIComponent(pedido.codigo)}/estado`, { metodo: 'PATCH', cuerpo });
            ctx.anunciar(`${datos.codigo}: ${HECHO[datos.estado] ?? NOMBRE_ESTADO[datos.estado]}`);
            alCambiar();
            // Si se ha cerrado el detalle mientras viajaba el PATCH, basta con la
            // lista: volver a pintarlo dejaría marcado como abierto un pedido
            // que no se ve, y luego no se podría reabrir
            if (sigueAbierto(pedido.codigo)) {
                pintar(datos);
                panel.caja.querySelector(`#${panel.idTitulo}`)?.focus();
            }
        } catch (error) {
            if (sesionCaducada(error)) {
                return;
            }
            if (!sigueAbierto(pedido.codigo)) {
                return;   // nadie mira ya este pedido: el error no tiene dónde enseñarse
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
                    alCambiar();
                    if (sigueAbierto(pedido.codigo)) {
                        const avisoActual = aviso.cloneNode(true);
                        pintar(datos);
                        panel.caja.querySelector('.detalle__aviso').replaceWith(avisoActual);
                    }
                } catch {
                    // Se queda el aviso: ya dice qué ha pasado
                }
            }
        }
    }

    return {
        abrir,
        cerrar: () => panel.cerrar(),
        elemento: panel.elemento,
        /** Código del pedido abierto o que se está cargando (null si no hay ninguno) */
        codigo: () => codigoAbierto,
    };
}

function pares(pedido) {
    return pedido.lineas.reduce((suma, l) => suma + l.cantidad, 0);
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
