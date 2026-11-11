/*
 * Lanzamientos: la cuadrícula de zapatillas anunciadas. Al pulsar una se abre
 * el panel de producto en modo consulta (información y fecha, sin compra).
 *
 * La zapatilla abierta se refleja en la URL (/lanzamientos?producto=<slug>),
 * igual que en el catálogo: un enlace copiado abre lo mismo.
 */
import { api, crear, fechaLarga } from './api.js';
import { abrirPanelProducto } from './panel-producto.js';

const lista = document.querySelector('[data-anuncios]');
const estado = document.querySelector('[data-estado]');

// Slugs anunciados: deciden el modo del panel. Desde la ficha se puede saltar a
// otro color del mismo modelo que ya esté a la venta, y ese sí se puede comprar
let anunciados = new Set();

function escribirUrl(slug) {
    const url = new URL(window.location.href);
    if (slug) {
        url.searchParams.set('producto', slug);
    } else {
        url.searchParams.delete('producto');
    }
    window.history.replaceState(null, '', url);
}

function mostrarEstado(texto, accion = null) {
    estado.replaceChildren();
    estado.hidden = texto === '';
    if (texto === '') {
        return;
    }
    estado.append(crear('p', '', texto));
    if (accion) {
        estado.append(accion);
    }
}

function tarjeta(par, indice) {
    const item = crear('li', 'anuncio');
    // Cascada corta; a partir de la octava ya no se espera más
    item.style.setProperty('--retraso', `${Math.min(indice, 8) * 45}ms`);

    const enlace = crear('a', 'anuncio__enlace');
    enlace.href = `/lanzamientos?producto=${encodeURIComponent(par.slug)}`;
    enlace.dataset.slug = par.slug;

    const foto = crear('div', 'anuncio__foto');
    const img = crear('img');
    img.src = par.imagen;
    img.alt = '';
    img.width = 1200;
    img.height = 900;
    img.loading = indice < 4 ? 'eager' : 'lazy';
    img.decoding = 'async';
    // Sin foto queda el gris: mejor un hueco limpio que el icono de imagen rota
    img.addEventListener('error', () => img.remove(), { once: true });
    foto.append(img);

    enlace.append(
        foto,
        crear('h2', 'anuncio__nombre', par.nombre),
        crear('p', 'anuncio__color', par.colorway),
        crear('p', 'anuncio__fecha', `Sale el ${fechaLarga(par.fecha_lanzamiento)}`),
    );
    item.append(enlace);
    return item;
}

async function cargar() {
    mostrarEstado('Cargando lanzamientos…');
    lista.setAttribute('aria-busy', 'true');
    try {
        const { datos } = await api('/api/lanzamientos');
        anunciados = new Set(datos.map((par) => par.slug));
        lista.replaceChildren(...datos.map(tarjeta));
        if (datos.length === 0) {
            const enlace = crear('a', 'boton', 'Ver el catálogo');
            enlace.href = '/catalogo';
            mostrarEstado('No hay lanzamientos anunciados.', enlace);
        } else {
            mostrarEstado('');
        }
        return true;
    } catch {
        lista.replaceChildren();
        const boton = crear('button', 'boton', 'Reintentar');
        boton.type = 'button';
        boton.addEventListener('click', cargar);
        mostrarEstado('No se han podido cargar los lanzamientos.', boton);
        return false;
    } finally {
        lista.removeAttribute('aria-busy');
    }
}

// --- Panel de producto -------------------------------------------------------------

/**
 * Un anunciado se abre en consulta. Lo que no está en la lista (otro color ya a
 * la venta, o la lista no ha cargado) se abre en compra: el panel pasa solo a
 * consulta si la ficha trae una fecha futura, así nunca se ofrece lo que no se vende.
 */
function abrir(slug, origen) {
    escribirUrl(slug);
    const modo = anunciados.has(slug) ? 'consulta' : 'compra';
    abrirPanelProducto(slug, {
        modo,
        origen,
        alCambiar: (otro) => {
            escribirUrl(otro);
            // El panel no cambia de modo por su cuenta al pasar de un color a otro
            if ((anunciados.has(otro) ? 'consulta' : 'compra') !== modo) {
                abrir(otro, origen);
            }
        },
        alCerrar: () => escribirUrl(null),
    });
}

lista.addEventListener('click', (evento) => {
    const enlace = evento.target.closest('a[data-slug]');
    // Ctrl o la rueda abren la ficha en otra pestaña, como cualquier enlace
    if (!enlace || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.button !== 0) {
        return;
    }
    evento.preventDefault();
    abrir(enlace.dataset.slug, enlace);
});

// --- Arranque ----------------------------------------------------------------------

const productoInicial = new URLSearchParams(window.location.search).get('producto');
// Se espera a la lista (es corta) para saber en qué modo abrir la ficha del enlace
cargar().then(() => {
    if (productoInicial) {
        abrir(productoInicial, lista.querySelector(`a[data-slug="${CSS.escape(productoInicial)}"]`));
    }
});
