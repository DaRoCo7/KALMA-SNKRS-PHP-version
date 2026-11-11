/*
 * Sección «Resumen»: las cifras de /api/gestion/resumen (permiso informes.ver).
 *
 * La pregunta que responde: ¿qué hay que hacer hoy? Por eso la línea bajo el
 * título dice cuántos pedidos esperan la confirmación del pago y cuántas tallas
 * hay que reponer, y al lado van los pedidos pendientes y los modelos con
 * menos stock. El recorrido de los pedidos se dibuja como una barra con un
 * tramo por estado: se ve de un vistazo dónde se acumula el trabajo.
 */

import { api, crear, precio } from '../api.js';
import {
    ESTADOS, NOMBRE_ESTADO, bloqueError, cabeceraSeccion, contar,
    fechaCorta, hashDe, horaActual, numero, sesionCaducada, textoError,
} from './comun.js';

const PENDIENTES_EN_LISTA = 5;

export function montar(contenedor, ctx) {
    const { cabecera, linea, acciones } = cabeceraSeccion('Resumen');
    linea.textContent = 'Cargando las cifras…';

    const sello = crear('span', 'seccion__sello');
    const actualizar = crear('button', 'control', 'Actualizar');
    actualizar.type = 'button';
    actualizar.addEventListener('click', () => cargar());
    acciones.append(sello, actualizar);

    const cuerpo = crear('div', 'resumen');
    contenedor.append(cabecera, cuerpo);

    let control = null;

    async function cargar() {
        control?.abort();
        control = new AbortController();
        const senal = control.signal;
        cuerpo.setAttribute('aria-busy', 'true');
        actualizar.disabled = true;
        if (!cuerpo.hasChildNodes()) {
            cuerpo.append(esqueleto());
        }

        try {
            const [resumen, pendientes] = await Promise.all([
                api('/api/gestion/resumen', { senal }),
                ctx.puede('pedidos.ver_todos')
                    ? api(`/api/gestion/pedidos?estado=pendiente&por_pagina=${PENDIENTES_EN_LISTA}`, { senal })
                    : Promise.resolve(null),
            ]);
            pintar(resumen.datos, pendientes);
            sello.textContent = `Actualizado a las ${horaActual()}`;
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            linea.textContent = 'No se han podido cargar las cifras.';
            cuerpo.replaceChildren(bloqueError(textoError(error), () => cargar()));
        } finally {
            if (!senal.aborted) {
                cuerpo.removeAttribute('aria-busy');
                actualizar.disabled = false;
            }
        }
    }

    function pintar(datos, pendientes) {
        const porEstado = datos.pedidos_por_estado;
        linea.textContent = [
            `${contar(porEstado.pendiente, 'pedido espera', 'pedidos esperan')} la confirmación del pago`,
            `${contar(datos.tallas_bajo_minimo, 'talla está', 'tallas están')} en el mínimo de stock o por debajo`,
        ].join(' · ');

        cuerpo.replaceChildren(
            cifras(datos),
            recorrido(porEstado),
            crear('div', 'resumen__pareja'),
        );
        const pareja = cuerpo.lastChild;
        if (pendientes) {
            pareja.append(listaPendientes(pendientes));
        }
        pareja.append(tablaStock(datos.modelos_menos_stock, datos.tallas_bajo_minimo));
    }

    // Lo que cambia en otra sección (un pedido cancelado repone stock) se refleja aquí
    document.addEventListener('panel:cambio', () => cargar(), { signal: ctx.senal });
    ctx.senal.addEventListener('abort', () => control?.abort());

    cargar();
}

/** Las cuatro cifras en una sola caja partida, sin una tarjeta por cifra */
function cifras(datos) {
    const caja = crear('section', 'tarjeta cifras');
    caja.setAttribute('aria-label', 'Ventas sin pedidos cancelados');
    const filas = [
        ['Ingresos', precio(datos.ingresos), 'sin pedidos cancelados', true],
        ['Pares vendidos', numero(datos.unidades_vendidas), 'sin pedidos cancelados'],
        ['Pedidos personalizados', numero(datos.pedidos_personalizados), 'bordado, caja o cordones'],
        ['Tallas que reponer', numero(datos.tallas_bajo_minimo), 'en el mínimo o por debajo'],
    ];
    for (const [etiqueta, valor, nota, principal] of filas) {
        const celda = crear('div', 'cifra' + (principal ? ' cifra--principal' : ''));
        celda.append(
            crear('p', 'cifra__etiqueta', etiqueta),
            crear('p', 'cifra__valor num', valor),
            crear('p', 'cifra__nota', nota),
        );
        caja.append(celda);
    }
    return caja;
}

/**
 * Barra del recorrido: un tramo por estado, de ancho proporcional a sus pedidos
 * y en grises que oscurecen según avanza el pedido. Debajo, cada estado con su
 * cifra y un enlace a la lista ya filtrada.
 */
function recorrido(porEstado) {
    const total = ESTADOS.reduce((suma, estado) => suma + porEstado[estado], 0);
    const caja = crear('section', 'tarjeta recorrido');
    const cabeza = crear('div', 'tarjeta__cabeza');
    cabeza.append(crear('h2', 'tarjeta__titulo', 'Pedidos por estado'), crear('p', 'tarjeta__meta num', contar(total, 'pedido', 'pedidos')));

    const barra = crear('div', 'recorrido__barra');
    barra.setAttribute('aria-hidden', 'true');
    for (const estado of ESTADOS) {
        if (porEstado[estado] > 0) {
            const tramo = crear('span', `recorrido__tramo recorrido__tramo--${estado}`);
            tramo.style.flexGrow = String(porEstado[estado]);
            barra.append(tramo);
        }
    }

    const lista = crear('ol', 'recorrido__estados');
    for (const estado of ESTADOS) {
        const elemento = crear('li', `recorrido__estado recorrido__estado--${estado}`);
        const enlace = crear('a', 'recorrido__enlace');
        enlace.href = hashDe('pedidos', { estado });
        const parte = total > 0 ? Math.round((porEstado[estado] / total) * 100) : 0;
        enlace.append(
            crear('span', 'recorrido__muestra'),
            crear('span', 'recorrido__nombre', NOMBRE_ESTADO[estado]),
            crear('span', 'recorrido__cifra num', numero(porEstado[estado])),
            crear('span', 'recorrido__parte num', `${parte} %`),
        );
        elemento.append(enlace);
        lista.append(elemento);
    }

    caja.append(cabeza, barra, lista);
    return caja;
}

/** Los pendientes más recientes: el trabajo de administración que más se repite */
function listaPendientes({ datos, paginacion }) {
    const caja = crear('section', 'tarjeta');
    const cabeza = crear('div', 'tarjeta__cabeza');
    const todos = crear('a', 'control control--texto', 'Ver todos');
    todos.href = hashDe('pedidos', { estado: 'pendiente' });
    cabeza.append(crear('h2', 'tarjeta__titulo', 'Esperan confirmación del pago'), todos);
    caja.append(cabeza);

    if (datos.length === 0) {
        caja.append(crear('p', 'vacio', 'No hay pedidos pendientes de confirmar.'));
        return caja;
    }

    const tabla = crear('table', 'tabla');
    tabla.innerHTML = '<thead><tr><th scope="col">Pedido</th><th scope="col">Cliente</th><th scope="col" class="num">Importe</th></tr></thead>';
    const filas = crear('tbody');
    for (const pedido of datos) {
        const fila = crear('tr');
        const codigo = crear('td');
        const enlace = crear('a', 'tabla__enlace', pedido.codigo);
        enlace.href = hashDe('pedidos', { estado: 'pendiente', pedido: pedido.codigo });
        codigo.append(enlace, crear('span', 'tabla__sub', fechaCorta(pedido.fecha_creacion)));
        const cliente = crear('td');
        cliente.append(crear('span', '', pedido.cliente.nombre), crear('span', 'tabla__sub', pedido.cliente.email));
        fila.append(codigo, cliente, crear('td', 'num', precio(pedido.total)));
        filas.append(fila);
    }
    tabla.append(filas);
    const envoltorio = crear('div', 'tabla-desplazable');
    envoltorio.append(tabla);
    caja.append(envoltorio);
    if (paginacion.total > datos.length) {
        caja.append(crear('p', 'tarjeta__pie', `${datos.length} de ${contar(paginacion.total, 'pendiente', 'pendientes')}`));
    }
    return caja;
}

function tablaStock(modelos, tallasBajoMinimo) {
    const caja = crear('section', 'tarjeta');
    const cabeza = crear('div', 'tarjeta__cabeza');
    cabeza.append(crear('h2', 'tarjeta__titulo', 'Modelos con menos stock'), crear('p', 'tarjeta__meta', 'suma de todas sus tallas'));
    caja.append(cabeza);

    if (modelos.length === 0) {
        caja.append(crear('p', 'vacio', 'No hay modelos a la venta.'));
        return caja;
    }

    const tabla = crear('table', 'tabla');
    tabla.innerHTML = '<thead><tr><th scope="col">Modelo</th><th scope="col">Código</th>'
        + '<th scope="col" class="num">Tallas bajo mínimo</th><th scope="col" class="num">Stock</th></tr></thead>';
    const filas = crear('tbody');
    for (const modelo of modelos) {
        const fila = crear('tr');
        const nombre = crear('td');
        nombre.append(crear('span', '', modelo.nombre), crear('span', 'tabla__sub', modelo.colorway));
        const bajo = crear('td', 'num');
        bajo.append(
            crear('span', modelo.tallas_bajo_minimo > 0 ? 'alerta' : '', numero(modelo.tallas_bajo_minimo)),
            crear('span', 'tabla__de', ` de ${numero(modelo.tallas)}`),
        );
        fila.append(nombre, crear('td', 'tabla__codigo', modelo.codigo_marca), bajo, crear('td', 'num', contar(modelo.stock_total, 'par', 'pares')));
        filas.append(fila);
    }
    tabla.append(filas);
    const envoltorio = crear('div', 'tabla-desplazable');
    envoltorio.append(tabla);
    caja.append(envoltorio);
    caja.append(crear('p', 'tarjeta__pie', `En total, ${contar(tallasBajoMinimo, 'talla', 'tallas')} en el mínimo o por debajo`));
    return caja;
}

/** Mientras llegan los datos: la misma forma con barras grises en lugar de cifras */
function esqueleto() {
    const caja = crear('div', 'tarjeta cifras');
    for (let i = 0; i < 4; i++) {
        const celda = crear('div', 'cifra');
        celda.append(crear('span', 'hueco hueco--etiqueta'), crear('span', 'hueco hueco--valor'));
        caja.append(celda);
    }
    return caja;
}
