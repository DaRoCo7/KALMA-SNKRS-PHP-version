/*
 * Sección «Pedidos»: lista de todos los pedidos con filtros y paginación. El
 * detalle lateral, desde el que se cambia el estado, está en pedido-detalle.js.
 *
 * API: GET /api/gestion/pedidos?estado&buscar&personalizado&pagina&por_pagina,
 * que trae también los recuentos por estado de las pestañas (una sola petición).
 *
 * Los filtros y el pedido abierto viven en el hash (#pedidos?estado=pendiente
 * &pedido=SF-2026-000021): recargar la página o volver atrás deja todo igual.
 */

import { api, crear, precio } from '../api.js';
import {
    ESTADOS, NOMBRE_ESTADO, bloqueError, cabeceraSeccion, contar, etiquetaEstado,
    fechaCorta, hashDe, numero, sesionCaducada, textoError,
} from './comun.js';
import { crearDetallePedido } from './pedido-detalle.js';
import { filaVacia, filasEsqueleto, pintarPaginas } from './piezas.js';

const POR_PAGINA = 20;
const ESPERA_BUSQUEDA = 300;   // ms: no se pide la lista en cada tecla

const COLUMNAS = 6;

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let controlLista = null;
    let temporizador = 0;
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

    const detalle = crearDetallePedido(ctx, {
        // Al cerrarse (Escape, el fondo o la X) se quita el pedido de la URL
        alCerrar() {
            if (params.pedido) {
                irConFiltros({ pedido: undefined, pagina: params.pagina });
            }
            marcarFila(null);
        },
        // Un cambio de estado mueve el pedido de pestaña: se recarga la lista y se avisa al resto
        alCambiar() {
            cargarLista();
            ctx.avisarCambio('pedidos');
        },
    });
    contenedor.append(cabecera, barra, bloqueLista, detalle.elemento);

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
            cuerpoTabla.replaceChildren(...filasEsqueleto(COLUMNAS));
        }

        try {
            // La lista y sus recuentos por estado (con los mismos filtros, para que las
            // cifras de las pestañas sigan a la búsqueda) llegan en la misma respuesta
            const lista = await api(`/api/gestion/pedidos?${consulta({ estado: params.estado, pagina: params.pagina ?? 1, por_pagina: POR_PAGINA })}`, { senal });
            primeraLista = false;
            avisoLista.replaceChildren();
            pintarCuentas(lista.recuentos);
            pintarFilas(lista.datos);
            pintarPaginas(paginas, lista.paginacion, (pagina) => hashDe('pedidos', { ...params, pagina, pedido: undefined }));
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

    function pintarCuentas({ total, por_estado: porEstado }) {
        for (const [estado, enlace] of enlacesEstado) {
            enlace.querySelector('.pestana__cifra').textContent = numero(estado ? (porEstado[estado] ?? 0) : total);
        }
        const todos = total;
        const pendientes = porEstado.pendiente ?? 0;
        const confirmados = porEstado.confirmado ?? 0;
        // La cola de cada rol: administración confirma pagos, almacén prepara lo confirmado
        const cola = ctx.puede('pedidos.confirmar_cancelar')
            ? `${contar(pendientes, 'espera', 'esperan')} la confirmación del pago`
            : `${contar(confirmados, 'confirmado espera', 'confirmados esperan')} a prepararse`;
        const filtrado = params.buscar || params.personalizado ? ' con estos filtros' : '';
        linea.textContent = `${contar(todos, 'pedido', 'pedidos')}${filtrado} · ${cola}`;
    }

    function pintarFilas(pedidos) {
        if (pedidos.length === 0) {
            const hayFiltros = params.estado || params.buscar || params.personalizado;
            cuerpoTabla.replaceChildren(filaVacia(COLUMNAS, 'No hay pedidos con estos filtros.', hayFiltros ? 'pedidos' : null));
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

    /** Resalta en la tabla la fila del pedido abierto (o ninguna con null) */
    function marcarFila(codigo) {
        for (const fila of cuerpoTabla.querySelectorAll('tr[data-codigo]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.codigo === codigo);
        }
    }

    function abrirDetalle(codigo) {
        marcarFila(codigo);
        detalle.abrir(codigo);
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
        if (params.pedido && params.pedido !== detalle.codigo()) {
            abrirDetalle(params.pedido);
        } else if (!params.pedido && detalle.elemento.open) {
            detalle.cerrar();
        }
    }

    // Un cambio hecho en otra sección también puede mover pedidos de estado
    document.addEventListener('panel:cambio', (evento) => {
        if (evento.detail?.origen !== 'pedidos') {
            cargarLista();
        }
    }, { signal: ctx.senal });
    // El detalle se cierra solo al salir (lo hace su panel lateral)
    ctx.senal.addEventListener('abort', () => {
        controlLista?.abort();
        window.clearTimeout(temporizador);
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
