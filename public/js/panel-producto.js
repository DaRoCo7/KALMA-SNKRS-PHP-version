/*
 * Panel de producto: la ficha completa encima de la página, con el fondo
 * difuminado. Se usa desde cualquier página que tenga una cuadrícula de
 * zapatillas (el catálogo, los lanzamientos...):
 *
 *   import { abrirPanelProducto } from '/js/panel-producto.js';
 *   abrirPanelProducto('nike-air-force-1-07-cw2288-111', { modo: 'compra' });
 *   abrirPanelProducto(slug, { modo: 'consulta', fechaLanzamiento: '2026-11-27' });
 *
 * Opciones:
 *   modo              'compra' (tallas, personalización y «Añadir a la cesta») o
 *                     'consulta' (solo la información y la fecha de salida).
 *   fechaLanzamiento  AAAA-MM-DD; si la ficha trae fecha_lanzamiento, manda la de la ficha.
 *   origen            elemento que recibe el foco al cerrar (por defecto, el que lo tenía).
 *   alCambiar(slug)   se llama al cambiar de color dentro del panel (para la URL).
 *   alCerrar()        se llama al cerrarse el panel.
 *
 * Si la ficha trae una fecha_lanzamiento futura, el panel pasa a consulta
 * aunque se haya pedido compra: una zapatilla anunciada no se puede pedir.
 *
 * El panel se construye la primera vez que se abre, así la página que lo usa
 * no necesita ningún HTML propio. La plantilla es fija; los datos de la API
 * entran siempre con textContent.
 */
import { api, ErrorApi, precio, talla, fechaLarga, esFutura, crear, prepararDialogo, cerrarDialogo } from './api.js';
import { anadirALaCesta, enCesta, abrirCesta, MAX_PARES } from './cesta.js';

const POCAS = 3;   // a partir de aquí se avisa de que quedan pocos pares

const NOMBRES_TIPO = { packaging: 'Caja', bordado: 'Bordado', cordones: 'Cordones', certificado: 'Certificado' };
const ORDEN_TIPOS = ['bordado', 'packaging', 'cordones', 'certificado'];

const PLANTILLA = `
    <div class="panel-producto__caja">
        <button class="panel-producto__cerrar boton boton--icono boton--claro" type="button" data-cerrar aria-label="Cerrar la ficha">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <div class="panel-producto__foto"><img alt="" width="1200" height="1200" data-foto></div>
        <div class="panel-producto__info" tabindex="-1" autofocus>
            <p class="panel-producto__estado" data-estado role="status"></p>
            <div class="panel-producto__ficha" data-ficha hidden>
                <h2 class="panel-producto__nombre" id="panel-producto-titulo" data-nombre></h2>
                <p class="panel-producto__color" data-color></p>
                <p class="panel-producto__precio" data-precio></p>
                <p class="panel-producto__descripcion" data-descripcion></p>
                <div class="panel-producto__colores" data-colores hidden>
                    <p class="panel-producto__etiqueta">Otros colores</p>
                    <ul data-lista-colores></ul>
                </div>
                <p class="panel-producto__salida" data-salida hidden></p>
                <form class="panel-producto__compra" data-compra novalidate>
                    <fieldset class="tallas" aria-describedby="panel-producto-stock">
                        <legend class="panel-producto__etiqueta">Talla (EU)</legend>
                        <div class="tallas__lista" data-tallas></div>
                        <p class="tallas__stock" id="panel-producto-stock" data-stock aria-live="polite"></p>
                    </fieldset>
                    <div class="panel-producto__cantidad">
                        <p class="panel-producto__etiqueta" id="panel-producto-cantidad">Cantidad</p>
                        <div class="paso" role="group" aria-labelledby="panel-producto-cantidad">
                            <button class="paso__boton" type="button" data-menos aria-label="Un par menos">−</button>
                            <output class="paso__valor" data-cantidad aria-live="polite">1</output>
                            <button class="paso__boton" type="button" data-mas aria-label="Un par más">+</button>
                        </div>
                    </div>
                    <div class="extras" data-extras hidden>
                        <p class="panel-producto__etiqueta">Personalización</p>
                        <div class="extras__grupos" data-grupos></div>
                    </div>
                    <div class="panel-producto__total">
                        <span>Total estimado</span>
                        <strong data-total></strong>
                    </div>
                    <p class="panel-producto__nota">El importe final lo calcula la tienda al hacer el pedido.</p>
                    <p class="panel-producto__mensaje" data-mensaje role="status"></p>
                    <div class="panel-producto__acciones">
                        <button class="boton boton--grande panel-producto__anadir" type="submit" data-anadir>Añadir a la cesta</button>
                        <button class="boton boton--claro panel-producto__ver-cesta" type="button" data-ver-cesta hidden>Ver la cesta</button>
                    </div>
                </form>
            </div>
        </div>
    </div>`;

let dialogo = null;
let nodos = {};
let estado = null;          // { ficha, cantidad, opciones } de lo que se está mirando
let peticion = null;        // para descartar respuestas de una ficha que ya no se mira
let opcionesApertura = {};
let origen = null;

function construir() {
    dialogo = document.createElement('dialog');
    dialogo.className = 'panel-producto';
    dialogo.setAttribute('aria-labelledby', 'panel-producto-titulo');
    dialogo.innerHTML = PLANTILLA;   // plantilla fija, sin datos
    document.body.append(dialogo);

    const q = (selector) => dialogo.querySelector(selector);
    nodos = {
        foto: q('[data-foto]'), estado: q('[data-estado]'), ficha: q('[data-ficha]'),
        nombre: q('[data-nombre]'), color: q('[data-color]'), precio: q('[data-precio]'),
        descripcion: q('[data-descripcion]'), colores: q('[data-colores]'), listaColores: q('[data-lista-colores]'),
        salida: q('[data-salida]'), compra: q('[data-compra]'), tallas: q('[data-tallas]'), stock: q('[data-stock]'),
        cantidad: q('[data-cantidad]'), menos: q('[data-menos]'), mas: q('[data-mas]'),
        extras: q('[data-extras]'), grupos: q('[data-grupos]'), total: q('[data-total]'),
        mensaje: q('[data-mensaje]'), anadir: q('[data-anadir]'), verCesta: q('[data-ver-cesta]'),
        info: q('.panel-producto__info'),
    };

    prepararDialogo(dialogo);
    q('[data-cerrar]').addEventListener('click', () => cerrarDialogo(dialogo));
    dialogo.addEventListener('close', () => {
        peticion?.abort();
        opcionesApertura.alCerrar?.();
        if (origen?.isConnected) {
            origen.focus();
        }
    });

    nodos.foto.addEventListener('error', () => nodos.foto.classList.add('sin-foto'));
    nodos.foto.addEventListener('load', () => nodos.foto.classList.remove('sin-foto'));

    nodos.tallas.addEventListener('change', alElegirTalla);
    nodos.menos.addEventListener('click', () => cambiarCantidad(-1));
    nodos.mas.addEventListener('click', () => cambiarCantidad(1));
    nodos.grupos.addEventListener('change', alCambiarExtras);
    nodos.grupos.addEventListener('input', alCambiarExtras);
    nodos.compra.addEventListener('submit', anadir);
    nodos.verCesta.addEventListener('click', () => {
        // Un solo panel a la vez: se cierra la ficha y se abre la cesta
        origen = null;
        dialogo.close();
        abrirCesta();
    });
    nodos.listaColores.addEventListener('click', (evento) => {
        const enlace = evento.target.closest('a[data-slug]');
        if (enlace) {
            evento.preventDefault();
            cargar(enlace.dataset.slug);
            opcionesApertura.alCambiar?.(enlace.dataset.slug);
        }
    });
}

/**
 * Abre el panel con la ficha de un producto.
 * @param {string} slug
 * @param {{modo?: 'compra'|'consulta', fechaLanzamiento?: string, origen?: Element, alCambiar?: Function, alCerrar?: Function}} opciones
 */
export function abrirPanelProducto(slug, opciones = {}) {
    if (!dialogo) {
        construir();
    }
    opcionesApertura = { modo: 'compra', ...opciones };
    origen = opciones.origen ?? document.activeElement;
    if (!dialogo.open) {
        dialogo.showModal();
    }
    cargar(slug);
}

export function cerrarPanelProducto() {
    if (dialogo?.open) {
        cerrarDialogo(dialogo);
    }
}

async function cargar(slug) {
    peticion?.abort();
    peticion = new AbortController();

    nodos.ficha.hidden = true;
    nodos.estado.hidden = false;
    nodos.estado.textContent = 'Cargando la zapatilla…';
    nodos.foto.removeAttribute('src');
    // En el escritorio se desliza la columna de la información; en el móvil, la caja entera
    nodos.info.scrollTop = 0;
    nodos.info.parentElement.scrollTop = 0;
    dialogo.setAttribute('aria-busy', 'true');

    try {
        const { datos } = await api(`/api/productos/${encodeURIComponent(slug)}`, { senal: peticion.signal });
        pintar(datos);
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }
        nodos.estado.textContent = error instanceof ErrorApi && error.estado === 404
            ? 'Esta zapatilla ya no está en el catálogo.'
            : 'No se ha podido cargar la zapatilla. Cierra el panel y vuelve a intentarlo.';
    } finally {
        dialogo.removeAttribute('aria-busy');
    }
}

function pintar(ficha) {
    // La ficha manda cuando trae el campo, aunque sea null: al cambiar de color
    // dentro del panel se puede pasar de un lanzamiento a un par que ya se vende,
    // y ni la fecha ni el modo con los que se abrió el panel valen para el nuevo.
    const fichaDecide = 'fecha_lanzamiento' in ficha;
    const fecha = fichaDecide ? ficha.fecha_lanzamiento : (opcionesApertura.fechaLanzamiento ?? null);
    const consulta = fichaDecide
        ? fecha !== null && esFutura(fecha)
        : opcionesApertura.modo === 'consulta' || (fecha !== null && esFutura(fecha));
    estado = { ficha, cantidad: 1, consulta };

    nodos.foto.src = ficha.imagen;
    nodos.foto.alt = `${ficha.nombre} ${ficha.colorway}`;
    nodos.nombre.textContent = ficha.nombre;
    nodos.color.textContent = ficha.colorway;
    nodos.precio.textContent = precio(ficha.precio);
    nodos.descripcion.textContent = ficha.descripcion ?? '';
    nodos.descripcion.hidden = !ficha.descripcion;
    pintarColores(ficha.otros_colores ?? []);

    // Modo consulta: la información y la fecha; nada que se pueda pedir
    nodos.compra.hidden = consulta;
    nodos.salida.hidden = !consulta;
    nodos.salida.textContent = fecha ? `Sale el ${fechaLarga(fecha)}` : 'Todavía no está a la venta';

    if (!consulta) {
        pintarTallas(ficha.tallas ?? []);
        pintarExtras(ficha.opciones_personalizacion ?? []);
        nodos.mensaje.textContent = '';
        nodos.verCesta.hidden = true;
        actualizarCompra();
    }

    nodos.estado.hidden = true;
    nodos.ficha.hidden = false;
}

function pintarColores(otros) {
    nodos.colores.hidden = otros.length === 0;
    nodos.listaColores.replaceChildren(...otros.map((otro) => {
        const item = crear('li');
        const enlace = crear('a', 'panel-producto__otro');
        enlace.href = `?producto=${encodeURIComponent(otro.slug)}`;
        enlace.dataset.slug = otro.slug;
        enlace.setAttribute('aria-label', otro.colorway);
        enlace.title = otro.colorway;
        const img = crear('img');
        img.src = otro.imagen;
        img.alt = '';
        img.width = 120;
        img.height = 120;
        img.loading = 'lazy';
        img.addEventListener('error', () => img.remove(), { once: true });
        enlace.append(img);
        item.append(enlace);
        return item;
    }));
}

// --- Tallas y cantidad --------------------------------------------------------

function pintarTallas(tallas) {
    nodos.tallas.replaceChildren(...tallas.map((t) => {
        const etiqueta = crear('label', 'talla');
        const radio = crear('input');
        radio.type = 'radio';
        radio.name = 'talla';
        radio.value = String(t.variante_id);
        radio.disabled = !t.disponible;
        const numero = crear('span', 'talla__numero', talla(t.talla));
        etiqueta.append(radio, numero);

        // El aviso va en la propia talla para verlo sin elegirla
        if (!t.disponible) {
            etiqueta.append(crear('span', 'talla__aviso', 'Agotada'));
            etiqueta.classList.add('talla--agotada');
        } else if (t.stock <= POCAS) {
            etiqueta.append(crear('span', 'talla__aviso', `Quedan ${t.stock}`));
        }
        return etiqueta;
    }));

    const hay = tallas.some((t) => t.disponible);
    nodos.stock.textContent = hay ? 'Elige tu talla.' : 'Agotada en todas las tallas.';
    nodos.anadir.disabled = !hay;
}

function tallaElegida() {
    const id = Number(nodos.tallas.querySelector('input:checked')?.value);
    return estado.ficha.tallas.find((t) => t.variante_id === id) ?? null;
}

/** Lo que aún se puede añadir de esa talla: su stock menos lo que ya hay en la cesta */
function maximoTalla(t) {
    return Math.max(0, Math.min(MAX_PARES, t.stock) - enCesta(t.variante_id));
}

function alElegirTalla() {
    const t = tallaElegida();
    const ya = enCesta(t.variante_id);
    const pares = t.stock === 1 ? '1 par' : `${t.stock} pares`;
    nodos.stock.textContent = ya > 0
        ? `Talla ${talla(t.talla)}: ${pares} disponibles, ${ya} ya en tu cesta.`
        : `Talla ${talla(t.talla)}: ${t.stock <= POCAS ? `solo ${t.stock === 1 ? 'queda 1 par' : `quedan ${pares}`}` : `${pares} disponibles`}.`;
    estado.cantidad = Math.max(1, Math.min(estado.cantidad, maximoTalla(t)));
    nodos.mensaje.textContent = '';
    actualizarCompra();
}

function cambiarCantidad(cambio) {
    const t = tallaElegida();
    const maximo = t ? maximoTalla(t) : MAX_PARES;
    estado.cantidad = Math.max(1, Math.min(maximo, estado.cantidad + cambio));
    actualizarCompra();
}

// --- Personalización -------------------------------------------------------------

/**
 * Una opción de cada tipo como máximo (regla de la API). Si un tipo tiene una
 * sola opción, es una casilla; si tiene varias, un grupo de radio con «Sin…».
 * La opción con max_caracteres (el bordado) abre su campo de texto.
 */
function pintarExtras(opciones) {
    const porTipo = new Map();
    opciones.forEach((o) => {
        if (!porTipo.has(o.tipo)) porTipo.set(o.tipo, []);
        porTipo.get(o.tipo).push(o);
    });
    const tipos = [...porTipo.keys()].sort((a, b) => indice(a) - indice(b));

    nodos.extras.hidden = tipos.length === 0;
    nodos.grupos.replaceChildren(...tipos.map((tipo) => grupoExtras(tipo, porTipo.get(tipo))));
}

function indice(tipo) {
    const i = ORDEN_TIPOS.indexOf(tipo);
    return i === -1 ? ORDEN_TIPOS.length : i;
}

function grupoExtras(tipo, opciones) {
    const grupo = crear('fieldset', 'extras__grupo');
    grupo.append(crear('legend', 'extras__tipo', NOMBRES_TIPO[tipo] ?? tipo));

    const varias = opciones.length > 1;
    if (varias) {
        grupo.append(opcionExtra({ id: '', nombre: 'Sin añadir', recargo: 0 }, tipo, 'radio', true));
    }
    opciones.forEach((o) => grupo.append(opcionExtra(o, tipo, varias ? 'radio' : 'checkbox', false)));
    return grupo;
}

function opcionExtra(opcion, tipo, clase, marcada) {
    const contenedor = crear('div', 'extra');
    const etiqueta = crear('label', 'extra__etiqueta');
    const control = crear('input');
    control.type = clase;
    control.name = `extra-${tipo}`;
    control.value = String(opcion.id);
    control.checked = marcada;

    const textos = crear('span', 'extra__textos');
    textos.append(crear('span', 'extra__nombre', opcion.nombre));
    if (opcion.descripcion) {
        textos.append(crear('span', 'extra__descripcion', opcion.descripcion));
    }
    etiqueta.append(control, textos);
    if (opcion.recargo > 0) {
        etiqueta.append(crear('span', 'extra__recargo', `+${precio(opcion.recargo)} por par`));
    }
    contenedor.append(etiqueta);

    if (opcion.max_caracteres) {
        const campo = crear('div', 'extra__campo');
        campo.hidden = true;
        const id = `extra-texto-${opcion.id}`;
        const rotulo = crear('label', 'extra__rotulo', `Nombre que se borda (hasta ${opcion.max_caracteres} letras)`);
        rotulo.htmlFor = id;
        const entrada = crear('input');
        entrada.id = id;
        entrada.type = 'text';
        entrada.maxLength = opcion.max_caracteres;
        entrada.autocomplete = 'off';
        entrada.spellcheck = false;
        entrada.dataset.textoDe = String(opcion.id);
        entrada.setAttribute('aria-describedby', `${id}-error`);
        const error = crear('p', 'campo__error');
        error.id = `${id}-error`;
        campo.append(rotulo, entrada, error);
        contenedor.append(campo);
    }
    return contenedor;
}

function alCambiarExtras(evento) {
    // Al marcar el bordado aparece su campo y recibe el foco; al quitarlo se esconde
    nodos.grupos.querySelectorAll('[data-texto-de]').forEach((entrada) => {
        const marcada = nodos.grupos.querySelector(`input[value="${entrada.dataset.textoDe}"]`)?.checked;
        const campo = entrada.closest('.extra__campo');
        const abriendo = campo.hidden && marcada;
        campo.hidden = !marcada;
        if (abriendo && evento.type === 'change') {
            entrada.focus();
        }
    });
    if (evento.target.matches('[data-texto-de]')) {
        // Solo letras y espacios, como pide la API; el resto se quita al escribir
        // y se pasa a mayúsculas, que es como se borda
        evento.target.value = evento.target.value.replace(/[^\p{L} ]/gu, '').toLocaleUpperCase('es');
        evento.target.nextElementSibling.textContent = '';
        evento.target.removeAttribute('aria-invalid');
    }
    actualizarCompra();
}

/** Opciones marcadas, con el texto del bordado si lo lleva */
function extrasElegidos() {
    const elegidas = [];
    nodos.grupos.querySelectorAll('input:checked').forEach((control) => {
        if (control.value === '') {
            return;
        }
        const opcion = estado.ficha.opciones_personalizacion.find((o) => String(o.id) === control.value);
        if (!opcion) {
            return;
        }
        const entrada = nodos.grupos.querySelector(`[data-texto-de="${opcion.id}"]`);
        elegidas.push({
            opcion_id: opcion.id,
            tipo: opcion.tipo,
            nombre: opcion.nombre,
            recargo: opcion.recargo,
            ...(entrada ? { texto: entrada.value.trim(), entrada, maximo: opcion.max_caracteres } : {}),
        });
    });
    return elegidas;
}

function actualizarCompra() {
    const t = tallaElegida();
    const maximo = t ? maximoTalla(t) : MAX_PARES;
    nodos.cantidad.textContent = String(estado.cantidad);
    nodos.menos.disabled = estado.cantidad <= 1;
    nodos.mas.disabled = estado.cantidad >= maximo;

    const recargo = extrasElegidos().reduce((suma, o) => suma + o.recargo, 0);
    nodos.total.textContent = precio((estado.ficha.precio + recargo) * estado.cantidad);
    nodos.anadir.disabled = t !== null && maximo === 0;
    if (t !== null && maximo === 0) {
        nodos.stock.textContent = `Ya tienes en la cesta todos los pares que quedan de la talla ${talla(t.talla)}.`;
    }
}

// --- Añadir ------------------------------------------------------------------------

function anadir(evento) {
    evento.preventDefault();
    const t = tallaElegida();
    if (!t) {
        nodos.stock.textContent = 'Elige una talla para añadirla a la cesta.';
        nodos.tallas.querySelector('input:not(:disabled)')?.focus();
        return;
    }

    const extras = extrasElegidos();
    for (const extra of extras) {
        if (extra.entrada && extra.texto === '') {
            mostrarErrorTexto(extra.entrada, 'Escribe el nombre que quieres bordado, o quita el bordado.');
            return;
        }
        if (extra.entrada && extra.texto.length > extra.maximo) {
            mostrarErrorTexto(extra.entrada, `Máximo ${extra.maximo} letras.`);
            return;
        }
    }

    const { ficha } = estado;
    const resultado = anadirALaCesta({
        variante_id: t.variante_id,
        slug: ficha.slug,
        nombre: ficha.nombre,
        colorway: ficha.colorway,
        imagen: ficha.imagen,
        precio: ficha.precio,
        talla: t.talla,
        stock: t.stock,
        cantidad: estado.cantidad,
        personalizacion: extras.map(({ opcion_id, tipo, nombre, recargo, texto }) => (
            texto ? { opcion_id, tipo, nombre, recargo, texto } : { opcion_id, tipo, nombre, recargo }
        )),
    });

    nodos.mensaje.textContent = resultado.mensaje;
    if (resultado.ok) {
        nodos.verCesta.hidden = false;
        estado.cantidad = 1;
        alElegirTalla();
        nodos.mensaje.textContent = resultado.mensaje;
    }
}

function mostrarErrorTexto(entrada, texto) {
    entrada.nextElementSibling.textContent = texto;
    entrada.setAttribute('aria-invalid', 'true');
    entrada.focus();
}
