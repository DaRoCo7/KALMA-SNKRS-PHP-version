/*
 * Catálogo: la cuadrícula de zapatillas a la venta con tres filtros (buscar,
 * categoría y orden) y «Cargar más». Al pulsar una tarjeta se abre el panel de
 * producto encima, y toda la compra sigue ahí sin salir de la página.
 *
 * Los filtros y la zapatilla abierta se reflejan en la URL
 * (/catalogo?categoria=3&producto=<slug>): un enlace copiado abre lo mismo.
 */
import { api, precio, crear } from './api.js';
import { abrirPanelProducto } from './panel-producto.js';

const POR_PAGINA = 12;

const formulario = document.querySelector('[data-filtros]');
const rejilla = document.querySelector('[data-rejilla]');
const estadoListado = document.querySelector('[data-estado-listado]');
const recuento = document.querySelector('[data-recuento]');
const botonMas = document.querySelector('[data-cargar-mas]');
const categorias = document.querySelector('[data-categorias]');

let pagina = 1;
let paginas = 1;
let peticion = null;

// --- Filtros <-> URL ------------------------------------------------------------

function leerUrl() {
    const url = new URLSearchParams(window.location.search);
    formulario.elements.buscar.value = url.get('buscar') ?? '';
    formulario.elements.orden.value = url.get('orden') ?? 'novedades';
    if (!formulario.elements.orden.value) {
        formulario.elements.orden.value = 'novedades';   // un orden que no existe vuelve al de siempre
    }
    return url.get('categoria') ?? '';
}

function filtros() {
    const datos = new FormData(formulario);
    const valores = {};
    ['buscar', 'categoria', 'orden'].forEach((campo) => {
        const valor = String(datos.get(campo) ?? '').trim();
        if (valor !== '' && !(campo === 'orden' && valor === 'novedades')) {
            valores[campo] = valor;
        }
    });
    return valores;
}

function escribirUrl(cambios) {
    const url = new URL(window.location.href);
    Object.entries(cambios).forEach(([clave, valor]) => {
        if (valor === null || valor === '') {
            url.searchParams.delete(clave);
        } else {
            url.searchParams.set(clave, valor);
        }
    });
    window.history.replaceState(null, '', url);
}

// --- Categorías: llegan de la API -------------------------------------------------

async function cargarCategorias(elegida) {
    try {
        const { datos } = await api('/api/catalogo/filtros');
        const opciones = [{ id: '', nombre: 'Todas' }, ...datos.categorias];
        categorias.replaceChildren(...opciones.map((c) => {
            const etiqueta = crear('label', 'chip');
            const radio = crear('input');
            radio.type = 'radio';
            radio.name = 'categoria';
            radio.value = String(c.id);
            radio.checked = String(c.id) === elegida;
            etiqueta.append(radio, crear('span', '', c.nombre));
            return etiqueta;
        }));
        if (!categorias.querySelector('input:checked')) {
            categorias.querySelector('input').checked = true;
        }
        categorias.closest('fieldset').hidden = false;
    } catch {
        // Sin categorías se puede seguir buscando y ordenando
    }
}

// --- Listado -----------------------------------------------------------------------------

async function cargar({ anadir = false } = {}) {
    peticion?.abort();
    peticion = new AbortController();
    const valores = filtros();
    const consulta = new URLSearchParams({ ...valores, pagina: String(pagina), por_pagina: String(POR_PAGINA) });

    rejilla.setAttribute('aria-busy', 'true');
    botonMas.disabled = true;
    if (!anadir) {
        mostrarEstado('Cargando zapatillas…');
        rejilla.classList.add('rejilla--cargando');
    } else {
        botonMas.textContent = 'Cargando…';
    }

    try {
        const respuesta = await api(`/api/productos?${consulta}`, { senal: peticion.signal });
        paginas = respuesta.paginacion.paginas;
        const tarjetas = respuesta.datos.map(tarjeta);
        if (anadir) {
            rejilla.append(...tarjetas);
            // El foco pasa a la primera zapatilla nueva: quien usa el teclado sigue por donde iba
            tarjetas[0]?.querySelector('a')?.focus({ preventScroll: true });
        } else {
            rejilla.replaceChildren(...tarjetas);
        }
        pintarPie(respuesta.paginacion);
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }
        if (!anadir) {
            rejilla.replaceChildren();
            recuento.textContent = '';
        }
        mostrarEstado(error.estado === 400 ? 'Algún filtro no es válido.' : 'No se ha podido cargar el catálogo.', 'Reintentar', () => cargar({ anadir }));
        botonMas.hidden = true;
    } finally {
        rejilla.removeAttribute('aria-busy');
        rejilla.classList.remove('rejilla--cargando');
        botonMas.disabled = false;
        botonMas.textContent = 'Cargar más';
    }
}

function pintarPie({ total }) {
    const vistas = rejilla.children.length;
    if (total === 0) {
        const hayFiltros = Object.keys(filtros()).some((c) => c !== 'orden');
        mostrarEstado('No hay zapatillas con esos filtros.', hayFiltros ? 'Quitar filtros' : null, quitarFiltros);
        recuento.textContent = '';
    } else {
        mostrarEstado('');
        recuento.textContent = `${vistas} de ${total} ${total === 1 ? 'zapatilla' : 'zapatillas'}`;
    }
    botonMas.hidden = pagina >= paginas;
}

function mostrarEstado(texto, accion = null, alPulsar = null) {
    estadoListado.replaceChildren();
    estadoListado.hidden = texto === '';
    if (texto === '') {
        return;
    }
    estadoListado.append(crear('p', '', texto));
    if (accion) {
        const boton = crear('button', 'boton', accion);
        boton.type = 'button';
        boton.addEventListener('click', alPulsar);
        estadoListado.append(boton);
    }
}

function quitarFiltros() {
    formulario.reset();
    const todas = categorias.querySelector('input[type="radio"]');
    if (todas) {
        todas.checked = true;
    }
    categorias.querySelectorAll('input[type="hidden"]').forEach((oculta) => { oculta.value = ''; });
    aplicarFiltros();
}

function tarjeta(producto, indice) {
    const item = crear('li', 'tarjeta');
    // Las tarjetas nuevas entran en cascada corta; el retraso se corta a los 8 primeros
    item.style.setProperty('--retraso', `${Math.min(indice, 8) * 40}ms`);

    const enlace = crear('a', 'tarjeta__enlace');
    enlace.href = `/catalogo?producto=${encodeURIComponent(producto.slug)}`;
    enlace.dataset.slug = producto.slug;

    const foto = crear('div', 'tarjeta__foto');
    const img = crear('img');
    img.src = producto.imagen;
    img.alt = '';
    img.width = 600;
    img.height = 600;
    img.loading = indice < 4 && pagina === 1 ? 'eager' : 'lazy';
    img.decoding = 'async';
    // Sin foto queda el fondo gris: mejor un hueco limpio que el icono de imagen rota
    img.addEventListener('error', () => img.remove(), { once: true });
    foto.append(img);

    const nombre = crear('h2', 'tarjeta__nombre', producto.nombre);
    const color = crear('p', 'tarjeta__color', producto.colorway);
    const pie = crear('p', 'tarjeta__precio', precio(producto.precio));
    if (producto.stock_total === 0) {
        pie.append(crear('span', 'tarjeta__aviso', ' · Agotada'));
    }
    enlace.append(foto, nombre, color, pie);
    item.append(enlace);
    return item;
}

function aplicarFiltros() {
    pagina = 1;
    const valores = filtros();
    escribirUrl({ buscar: valores.buscar ?? null, categoria: valores.categoria ?? null, orden: valores.orden ?? null });
    cargar();
}

// --- Panel de producto ------------------------------------------------------------------

function abrir(slug, origen) {
    escribirUrl({ producto: slug });
    abrirPanelProducto(slug, {
        modo: 'compra',
        origen,
        alCambiar: (otro) => escribirUrl({ producto: otro }),
        alCerrar: () => escribirUrl({ producto: null }),
    });
}

rejilla.addEventListener('click', (evento) => {
    const enlace = evento.target.closest('a[data-slug]');
    // Ctrl o la rueda abren la ficha en otra pestaña, como cualquier enlace
    if (!enlace || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.button !== 0) {
        return;
    }
    evento.preventDefault();
    abrir(enlace.dataset.slug, enlace);
});

// --- Arranque ---------------------------------------------------------------------------------

let espera = null;
formulario.addEventListener('input', (evento) => {
    // Al escribir se espera a que la persona pare; el resto de filtros es inmediato
    window.clearTimeout(espera);
    if (evento.target.name === 'buscar') {
        espera = window.setTimeout(aplicarFiltros, 300);
    }
});
formulario.addEventListener('change', (evento) => {
    if (evento.target.name !== 'buscar') {
        aplicarFiltros();
    }
});
formulario.addEventListener('submit', (evento) => {
    evento.preventDefault();
    window.clearTimeout(espera);
    aplicarFiltros();
});

botonMas.addEventListener('click', () => {
    pagina += 1;
    cargar({ anadir: true });
});

const categoriaInicial = leerUrl();
// Mientras llegan las categorías, el filtro de la URL ya se aplica con un campo oculto
const oculta = crear('input');
oculta.type = 'hidden';
oculta.name = 'categoria';
oculta.value = categoriaInicial;
categorias.append(oculta);
cargarCategorias(categoriaInicial);
cargar();

const productoInicial = new URLSearchParams(window.location.search).get('producto');
if (productoInicial) {
    abrir(productoInicial, null);
}
