/*
 * Área del cliente (/cuenta): lista de pedidos con su detalle desplegable,
 * cancelación con confirmación y formulario del perfil.
 *
 * Todo lo pide a la API (contrato, punto 9.3, «Área del cliente») y lo pinta
 * con textContent a través de crear(): nada de lo que llega se mete con innerHTML.
 */
import {
    api, precio, talla, fechaLarga, crear, prepararDialogo, cerrarDialogo, movimientoReducido, sesionCaducada,
} from './api.js';

/** Textos de cada estado. Tienen que entenderse sin conocer el flujo interno */
const ESTADOS = {
    pendiente: 'Pendiente de pago',
    confirmado: 'Pago confirmado',
    preparacion: 'En preparación',
    enviado: 'Enviado',
    entregado: 'Entregado',
    cancelado: 'Cancelado',
};

const POR_PAGINA = 20;

const lista = document.querySelector('[data-pedidos]');
const estadoLista = document.querySelector('[data-pedidos-estado]');
const botonMas = document.querySelector('[data-pedidos-mas]');

const formPerfil = document.querySelector('[data-perfil]');
const avisoPerfil = document.querySelector('[data-perfil-aviso]');

const dialogo = document.querySelector('[data-confirmar]');
const formConfirmar = document.querySelector('[data-confirmar-formulario]');
const errorConfirmar = document.querySelector('[data-confirmar-error]');

let paginaActual = 0;
let totalPaginas = 1;
/** Código del pedido que se está confirmando en el diálogo */
let pedidoACancelar = null;

// --- Utilidades ---------------------------------------------------------------------

/** «2026-11-08T19:15:00+01:00» -> «19:15». Se lee del texto, como fechaLarga, para no depender de la zona del navegador */
function hora(iso) {
    return String(iso).slice(11, 16);
}

function pares(unidades) {
    return unidades === 1 ? '1 par' : `${unidades} pares`;
}

/**
 * Sin sesión (caducada a los 30 min) se vuelve al login y, después, aquí. El
 * 403 CSRF de una sesión caducada ya llega de api() convertido en 401.
 */
function siSesionCaducada(error) {
    return sesionCaducada(error, window.location.pathname);
}

function etiquetaEstado(estado) {
    return crear('span', `estado estado--${estado}`, ESTADOS[estado] ?? estado);
}

function mostrarEstadoLista(texto, enlaceCatalogo = false) {
    estadoLista.replaceChildren(crear('p', '', texto));
    if (enlaceCatalogo) {
        const enlace = crear('a', 'boton', 'Ver el catálogo');
        enlace.href = '/catalogo';
        estadoLista.append(enlace);
    }
    estadoLista.hidden = false;
}

// --- Lista de pedidos ---------------------------------------------------------------

/** Una fila: el botón que abre el detalle y, debajo, el hueco donde se pinta */
function filaPedido(pedido) {
    const item = crear('li', 'pedido');
    item.dataset.codigo = pedido.codigo;

    const boton = crear('button', 'pedido__resumen');
    boton.type = 'button';
    boton.setAttribute('aria-expanded', 'false');
    const idDetalle = `detalle-${pedido.codigo}`;
    boton.setAttribute('aria-controls', idDetalle);

    const codigo = crear('span', 'pedido__codigo', pedido.codigo);
    const fecha = crear('span', 'pedido__fecha', fechaLarga(pedido.fecha_creacion));
    const cuantos = crear('span', 'pedido__pares', pares(pedido.unidades));
    const total = crear('span', 'pedido__total', precio(pedido.total));
    const flecha = crear('span', 'pedido__flecha');
    flecha.setAttribute('aria-hidden', 'true');
    boton.append(codigo, etiquetaEstado(pedido.estado), fecha, cuantos, total, flecha);

    const detalle = crear('div', 'pedido__detalle');
    detalle.id = idDetalle;
    detalle.hidden = true;

    item.append(boton, detalle);
    return item;
}

async function cargarPedidos({ reiniciar = false } = {}) {
    if (reiniciar) {
        paginaActual = 0;
    }
    const pagina = paginaActual + 1;
    botonMas.disabled = true;
    try {
        const respuesta = await api(`/api/pedidos?pagina=${pagina}&por_pagina=${POR_PAGINA}`);
        paginaActual = pagina;
        totalPaginas = respuesta.paginacion?.paginas ?? 1;

        const filas = respuesta.datos.map(filaPedido);
        if (reiniciar) {
            lista.replaceChildren(...filas);
        } else {
            lista.append(...filas);
        }

        if (lista.children.length === 0) {
            mostrarEstadoLista('Todavía no has hecho ningún pedido. Cuando encuentres tu par, aquí podrás seguirlo paso a paso.', true);
        } else {
            estadoLista.hidden = true;
        }
        botonMas.hidden = paginaActual >= totalPaginas;
    } catch (error) {
        if (!siSesionCaducada(error)) {
            mostrarEstadoLista(`No se han podido cargar tus pedidos. ${error.message}`);
        }
    } finally {
        botonMas.disabled = false;
    }
}

async function alPulsarPedido(evento) {
    const boton = evento.target.closest('.pedido__resumen');
    if (!boton) {
        return;
    }
    const item = boton.closest('.pedido');
    const detalle = item.querySelector('.pedido__detalle');
    const abrir = boton.getAttribute('aria-expanded') !== 'true';

    boton.setAttribute('aria-expanded', String(abrir));
    detalle.hidden = !abrir;
    if (!abrir) {
        return;
    }
    // El detalle se pide cada vez que se abre: el estado puede haber cambiado
    // desde el área interna mientras la página estaba abierta
    if (!detalle.hasChildNodes()) {
        detalle.append(crear('p', 'pedido__cargando', 'Cargando el pedido…'));
    }
    try {
        const { datos } = await api(`/api/pedidos/${encodeURIComponent(item.dataset.codigo)}`);
        pintarDetalle(item, datos);
    } catch (error) {
        if (!siSesionCaducada(error)) {
            detalle.replaceChildren(crear('p', 'pedido__cargando', `No se ha podido cargar el pedido. ${error.message}`));
        }
    }
}

// --- Detalle --------------------------------------------------------------------------

function lineaPedido(linea) {
    const item = crear('li', 'linea');

    const foto = crear('img', 'linea__foto');
    foto.src = linea.imagen;
    foto.alt = '';           // el nombre va justo al lado: la foto no añade nada al lector
    foto.width = 96;
    foto.height = 96;
    foto.loading = 'lazy';
    foto.decoding = 'async';
    // Sin foto queda el hueco gris del suelo, no el icono de imagen rota
    foto.addEventListener('error', () => foto.classList.add('linea__foto--vacia'), { once: true });

    const texto = crear('div', 'linea__texto');
    const nombre = crear('a', 'linea__nombre', linea.producto);
    nombre.href = `/catalogo?producto=${encodeURIComponent(linea.slug)}`;
    texto.append(nombre, crear('p', 'linea__color', linea.color));
    const unidades = linea.cantidad === 1 ? '1 par' : `${linea.cantidad} pares`;
    texto.append(crear('p', 'linea__talla', `Talla ${talla(linea.talla)} · ${unidades} · ${precio(linea.precio_unitario)} el par`));

    if (linea.personalizacion.length > 0) {
        const extras = crear('ul', 'linea__extras');
        extras.setAttribute('aria-label', 'Personalización');
        linea.personalizacion.forEach((opcion) => {
            const extra = crear('li', '', opcion.nombre);
            if (opcion.texto) {
                extra.append(' ', crear('span', 'linea__bordado', `«${opcion.texto}»`));
            }
            extra.append(crear('span', 'linea__recargo', `+${precio(opcion.recargo)}/par`));
            extras.append(extra);
        });
        texto.append(extras);
    }

    item.append(foto, texto, crear('p', 'linea__importe', precio(linea.importe)));
    return item;
}

function historialPedido(historial) {
    const linea = crear('ol', 'historial');
    historial.forEach((paso, indice) => {
        const item = crear('li', `historial__paso historial__paso--${paso.estado_nuevo}`);
        if (indice === historial.length - 1) {
            item.setAttribute('aria-current', 'step');
        }
        const cuando = crear('time', 'historial__fecha', `${fechaLarga(paso.fecha)}, ${hora(paso.fecha)}`);
        cuando.dateTime = paso.fecha;
        item.append(crear('span', 'historial__estado', ESTADOS[paso.estado_nuevo] ?? paso.estado_nuevo), cuando);
        if (paso.comentario) {
            item.append(crear('p', 'historial__comentario', paso.comentario));
        }
        linea.append(item);
    });
    return linea;
}

function pintarDetalle(item, pedido) {
    const detalle = item.querySelector('.pedido__detalle');

    const lineas = crear('ul', 'pedido__lineas');
    lineas.append(...pedido.lineas.map(lineaPedido));

    const total = crear('p', 'pedido__suma');
    total.append(crear('span', '', 'Total'), crear('span', 'pedido__suma-importe', precio(pedido.total)));

    const envio = crear('dl', 'pedido__envio');
    envio.append(crear('dt', '', 'Envío a'), crear('dd', '', pedido.direccion_envio));
    if (pedido.codigo_seguimiento) {
        envio.append(crear('dt', '', 'Seguimiento'), crear('dd', 'pedido__seguimiento', pedido.codigo_seguimiento));
    }
    if (pedido.notas) {
        envio.append(crear('dt', '', 'Notas'), crear('dd', '', pedido.notas));
    }

    const columnaHistorial = crear('div', 'pedido__historial');
    columnaHistorial.append(crear('h3', 'pedido__subtitulo', 'Seguimiento'), historialPedido(pedido.historial));

    const columnaLineas = crear('div', 'pedido__contenido');
    columnaLineas.append(crear('h3', 'pedido__subtitulo', 'Zapatillas'), lineas, total, envio);

    if (pedido.se_puede_cancelar) {
        const cancelar = crear('button', 'boton boton--claro pedido__cancelar', 'Cancelar pedido');
        cancelar.type = 'button';
        cancelar.dataset.cancelar = pedido.codigo;
        columnaLineas.append(cancelar);
    }

    detalle.replaceChildren(columnaLineas, columnaHistorial);

    // La fila también se pone al día: si el estado cambió fuera, que no mienta
    const etiqueta = item.querySelector('.pedido__resumen .estado');
    etiqueta.replaceWith(etiquetaEstado(pedido.estado));
}

// --- Cancelar --------------------------------------------------------------------------

function pedirConfirmacion(codigo, origen) {
    pedidoACancelar = { codigo, origen };
    dialogo.querySelector('[data-confirmar-codigo]').textContent = codigo;
    formConfirmar.reset();
    errorConfirmar.textContent = '';
    dialogo.showModal();
    // El foco empieza en «Volver»: la opción que no destruye nada
    dialogo.querySelector('[data-confirmar-volver]').focus();
}

async function confirmarCancelacion(evento) {
    evento.preventDefault();
    if (!pedidoACancelar) {
        return;
    }
    const { codigo } = pedidoACancelar;
    const boton = dialogo.querySelector('[data-confirmar-si]');
    const motivo = formConfirmar.elements.motivo.value.trim();

    boton.disabled = true;
    boton.textContent = 'Cancelando…';
    errorConfirmar.textContent = '';
    try {
        const cuerpo = motivo === '' ? {} : { motivo };
        const { datos } = await api(`/api/pedidos/${encodeURIComponent(codigo)}/cancelar`, { metodo: 'POST', cuerpo });
        cerrarDialogo(dialogo);
        pedidoACancelar = null;

        // Se vuelve a pedir la lista entera para que la fila muestre el estado
        // nuevo; el detalle se pinta con lo que ya devolvió la cancelación
        await cargarPedidos({ reiniciar: true });
        reabrir(codigo, datos);
    } catch (error) {
        if (siSesionCaducada(error)) {
            return;
        }
        // 409: alguien de la tienda lo ha movido de estado mientras tanto
        errorConfirmar.textContent = error.estado === 409
            ? 'Este pedido ya no se puede cancelar: su estado ha cambiado. Cierra y vuelve a abrirlo para verlo al día.'
            : error.message;
    } finally {
        boton.disabled = false;
        boton.textContent = 'Sí, cancelar el pedido';
    }
}

/** Tras recargar la lista, el pedido cancelado sigue abierto y con el foco: no se pierde el sitio */
function reabrir(codigo, datos) {
    const item = [...lista.children].find((li) => li.dataset.codigo === codigo);
    if (!item) {
        return;
    }
    const boton = item.querySelector('.pedido__resumen');
    boton.setAttribute('aria-expanded', 'true');
    item.querySelector('.pedido__detalle').hidden = false;
    pintarDetalle(item, datos);
    const aviso = crear('p', 'pedido__aviso', 'Pedido cancelado. Las tallas han vuelto a la tienda.');
    aviso.setAttribute('role', 'status');
    item.querySelector('.pedido__contenido').prepend(aviso);
    boton.focus({ preventScroll: movimientoReducido() });
}

// --- Perfil ------------------------------------------------------------------------------

function errorCampo(campo, texto) {
    const input = formPerfil.elements[campo];
    if (!input) {
        return false;
    }
    input.setAttribute('aria-invalid', 'true');
    document.getElementById(`perfil-${campo}-error`).textContent = texto;
    return true;
}

function limpiarErrores() {
    formPerfil.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    formPerfil.querySelectorAll('.campo__error').forEach((p) => {
        p.textContent = '';
    });
}

function rellenarPerfil(perfil) {
    formPerfil.querySelector('[data-perfil-email]').textContent = perfil.email;
    [...formPerfil.elements].forEach((control) => {
        if (control.name && control.name in perfil) {
            control.value = perfil[control.name] ?? '';
        }
        control.disabled = false;
    });
}

/** El nombre sale en el saludo y en la cabecera: si cambia, se cambia allí también */
function actualizarNombre(nombre) {
    document.querySelectorAll('[data-saludo-nombre], .cuenta > .cuenta__nombre').forEach((nodo) => {
        nodo.textContent = nombre;
    });
    const menu = document.querySelector('.menu__nombre');
    if (menu) {
        menu.textContent = `Hola, ${nombre}`;
    }
}

async function cargarPerfil() {
    try {
        const { datos } = await api('/api/perfil');
        rellenarPerfil(datos);
    } catch (error) {
        if (!siSesionCaducada(error)) {
            avisoPerfil.textContent = `No se han podido cargar tus datos. ${error.message}`;
        }
    }
}

let temporizadorAviso = null;

async function guardarPerfil(evento) {
    evento.preventDefault();
    const boton = formPerfil.querySelector('[type="submit"]');
    if (boton.disabled) {
        return;   // un segundo Enter mientras se envía no manda otro PATCH
    }
    limpiarErrores();
    window.clearTimeout(temporizadorAviso);
    avisoPerfil.textContent = '';
    avisoPerfil.classList.remove('datos__aviso--ok');

    // Se envían todos los campos: la API solo escribe los que cambian
    const cuerpo = Object.fromEntries(
        [...new FormData(formPerfil)].map(([campo, valor]) => [campo, String(valor).trim()]),
    );

    boton.disabled = true;
    boton.textContent = 'Guardando…';
    try {
        const { datos } = await api('/api/perfil', { metodo: 'PATCH', cuerpo });
        rellenarPerfil(datos);
        actualizarNombre(datos.nombre);
        avisoPerfil.textContent = 'Cambios guardados.';
        avisoPerfil.classList.add('datos__aviso--ok');
        // El aviso se va solo: si se queda, al volver a editar parece que lo nuevo ya está guardado
        temporizadorAviso = window.setTimeout(() => {
            avisoPerfil.textContent = '';
            avisoPerfil.classList.remove('datos__aviso--ok');
        }, 5000);
    } catch (error) {
        if (siSesionCaducada(error)) {
            return;
        }
        const errores = error.detalles?.errores ?? {};
        const pintados = Object.entries(errores).filter(([campo, texto]) => errorCampo(campo, texto));
        if (pintados.length > 0) {
            avisoPerfil.textContent = 'Revisa los campos marcados.';
            formPerfil.elements[pintados[0][0]].focus();
        } else {
            avisoPerfil.textContent = error.message;
        }
    } finally {
        boton.disabled = false;
        boton.textContent = 'Guardar cambios';
    }
}

// --- Arranque ------------------------------------------------------------------------------

if (lista) {
    lista.addEventListener('click', (evento) => {
        const cancelar = evento.target.closest('[data-cancelar]');
        if (cancelar) {
            pedirConfirmacion(cancelar.dataset.cancelar, cancelar);
            return;
        }
        alPulsarPedido(evento);
    });
    botonMas.addEventListener('click', () => cargarPedidos());
    cargarPedidos({ reiniciar: true });
}

if (dialogo) {
    prepararDialogo(dialogo);
    formConfirmar.addEventListener('submit', confirmarCancelacion);
    dialogo.querySelector('[data-confirmar-volver]').addEventListener('click', () => cerrarDialogo(dialogo));
    // Al cerrarse (con Volver, Escape o el fondo) el foco vuelve al botón que lo abrió
    dialogo.addEventListener('close', () => {
        const origen = pedidoACancelar?.origen;
        if (origen?.isConnected) {
            origen.focus();
        }
        pedidoACancelar = null;
    });
}

if (formPerfil) {
    formPerfil.addEventListener('submit', guardarPerfil);
    // Al corregir un campo, su error deja de estar a la vista
    formPerfil.addEventListener('input', (evento) => {
        const campo = evento.target.name;
        if (evento.target.getAttribute('aria-invalid') === 'true') {
            evento.target.removeAttribute('aria-invalid');
            document.getElementById(`perfil-${campo}-error`).textContent = '';
        }
    });
    cargarPerfil();
}
