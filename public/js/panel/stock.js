/*
 * Sección «Stock» (almacén y administración, permiso stock.gestionar): cada
 * zapatilla en una fila con el stock de sus tallas y el total, un buscador,
 * pestañas por categoría y el filtro «Poco stock». Al pulsar una talla se abre
 * el panel lateral con su ajuste manual y su historial de movimientos.
 *
 * API: GET /api/gestion/stock?buscar&categoria_id&pagina&por_pagina,
 * PATCH /api/gestion/variantes/{id}/stock y GET /api/gestion/variantes/{id}/movimientos.
 * Las categorías salen de GET /api/catalogo/filtros.
 *
 * La API devuelve tallas sueltas; aquí se agrupan por zapatilla y por eso se
 * piden todas las páginas (unas 200 tallas): con la paginación de la API una
 * zapatilla podría quedar partida en dos páginas.
 *
 * «Poco stock» usa el mismo umbral que el resto del sistema: una talla está
 * baja si stock <= stock_minimo (lo calcula la BD en bajo_minimo) y no cuenta
 * si la zapatilla es un lanzamiento que todavía no ha salido, igual que el
 * filtro bajo_minimo de la API y las cifras del resumen.
 *
 * Filtros y talla abierta en el hash: #stock?categoria=3&poco=1&talla=42
 */

import { api, crear, talla as formatoTalla } from '../api.js';
import { bloqueError, cabeceraSeccion, contar, fechaCorta, hashDe, numero, sesionCaducada, textoError } from './comun.js';
import {
    cabezaLateral, campoFormulario, conSigno, etiqueta, fechaDia, filaVacia, filasEsqueleto,
    fotoProducto, lateral as crearLateral, mostrarErrores, todasLasPaginas,
} from './piezas.js';

const ESPERA_BUSQUEDA = 300;
const MOVIMIENTOS_POR_PAGINA = 20;
const COLUMNAS = 5;

const TIPOS_MOVIMIENTO = {
    entrada: 'Entrada',
    venta: 'Venta',
    cancelacion: 'Cancelación',
    ajuste: 'Ajuste',
};

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let categorias = [];
    let tallas = [];             // última respuesta de la API, sin agrupar
    let controlLista = null;
    let controlDetalle = null;
    let temporizador = 0;
    let primeraLista = true;
    let tallaAbierta = null;     // variante_id del panel lateral

    // --- Estructura ------------------------------------------------------------

    const { cabecera, linea } = cabeceraSeccion('Stock');
    linea.textContent = 'Cargando el stock…';

    const barra = crear('div', 'filtros');
    const pestanas = crear('nav', 'pestanas');
    pestanas.setAttribute('aria-label', 'Filtrar por categoría');

    const busqueda = crear('form', 'filtros__busqueda');
    busqueda.setAttribute('role', 'search');
    const etiquetaBuscar = crear('label', 'oculto', 'Buscar por nombre, código de Nike o etiqueta RFID');
    etiquetaBuscar.htmlFor = 'stock-buscar';
    const campoBuscar = crear('input', 'campo-texto');
    Object.assign(campoBuscar, {
        id: 'stock-buscar', type: 'search', maxLength: 60, placeholder: 'Nombre, código o RFID', autocomplete: 'off',
    });
    const etiquetaPoco = crear('label', 'casilla');
    const casillaPoco = crear('input');
    casillaPoco.type = 'checkbox';
    etiquetaPoco.append(casillaPoco, crear('span', '', 'Solo poco stock'));
    busqueda.append(etiquetaBuscar, campoBuscar, etiquetaPoco);
    barra.append(pestanas, busqueda);

    const bloqueLista = crear('section', 'tarjeta tarjeta--lista');
    bloqueLista.setAttribute('aria-labelledby', 'panel-titulo');
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla tabla--inv tabla--stock');
    tabla.innerHTML = '<caption class="oculto">Stock por zapatilla y talla</caption>'
        + '<thead><tr><th scope="col">Zapatilla</th><th scope="col">Código</th><th scope="col">Categoría</th>'
        + '<th scope="col">Stock por talla</th><th scope="col" class="num">Total</th></tr></thead>';
    const cuerpoTabla = crear('tbody');
    tabla.append(cuerpoTabla);
    envoltorio.append(tabla);
    const avisoLista = crear('div', 'tarjeta__aviso');
    const pie = crear('p', 'tarjeta__pie');
    bloqueLista.append(avisoLista, envoltorio, pie);

    const lateral = crearLateral(ctx, () => {
        tallaAbierta = null;
        controlDetalle?.abort();
        if (params.talla) {
            irConFiltros({ talla: undefined });
        }
        marcarAbierta();
    });

    // Leyenda: qué significa cada marca de las tallas, sin tener que adivinarlo
    const leyenda = crear('p', 'leyenda-inv');
    leyenda.append(
        crear('span', 'leyenda-inv__muestra talla-inv--baja', '0'), crear('span', '', 'en el mínimo o por debajo'),
        crear('span', 'leyenda-inv__muestra talla-inv--apagada', '0'), crear('span', '', 'talla desactivada'),
    );

    contenedor.append(cabecera, barra, leyenda, bloqueLista, lateral.elemento);

    // --- Filtros -----------------------------------------------------------------

    function irConFiltros(cambios, opciones = { reemplazar: true }) {
        ctx.irA('stock', { ...params, ...cambios }, opciones);
    }

    busqueda.addEventListener('submit', (evento) => {
        evento.preventDefault();
        window.clearTimeout(temporizador);
        irConFiltros({ buscar: campoBuscar.value.trim(), talla: undefined });
    });
    campoBuscar.addEventListener('input', () => {
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(() => irConFiltros({ buscar: campoBuscar.value.trim(), talla: undefined }), ESPERA_BUSQUEDA);
    });
    casillaPoco.addEventListener('change', () => irConFiltros({ poco: casillaPoco.checked ? '1' : undefined }));

    function pintarPestanas() {
        pestanas.replaceChildren();
        for (const categoria of [{ id: '', nombre: 'Todas' }, ...categorias]) {
            const enlace = crear('a', 'pestana', categoria.nombre);
            const id = String(categoria.id);
            enlace.href = hashDe('stock', { ...params, categoria: id, talla: undefined });
            if ((params.categoria ?? '') === id) {
                enlace.setAttribute('aria-current', 'true');
            }
            pestanas.append(enlace);
        }
    }

    function pintarFiltros() {
        pintarPestanas();
        if (document.activeElement !== campoBuscar) {
            campoBuscar.value = params.buscar ?? '';
        }
        casillaPoco.checked = params.poco === '1';
    }

    // --- Lista -------------------------------------------------------------------

    async function cargarLista() {
        controlLista?.abort();
        controlLista = new AbortController();
        const senal = controlLista.signal;
        bloqueLista.setAttribute('aria-busy', 'true');
        if (primeraLista) {
            cuerpoTabla.replaceChildren(...filasEsqueleto(COLUMNAS));
        }

        const consulta = new URLSearchParams(Object.entries({ buscar: params.buscar, categoria_id: params.categoria })
            .filter(([, v]) => v !== undefined && v !== '')).toString();
        try {
            const [filas, filtros] = await Promise.all([
                todasLasPaginas(`/api/gestion/stock${consulta ? '?' + consulta : ''}`, senal),
                categorias.length ? null : api('/api/catalogo/filtros', { senal }),
            ]);
            if (filtros) {
                categorias = filtros.datos.categorias;
                pintarPestanas();
            }
            primeraLista = false;
            tallas = filas;
            avisoLista.replaceChildren();
            pintarLista();
            // Si el panel lateral está abierto, su cabecera muestra el stock nuevo
            if (tallaAbierta && lateral.elemento.open) {
                pintarResumenTalla();
            }
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            if (primeraLista) {
                cuerpoTabla.replaceChildren();
            }
            linea.textContent = 'No se ha podido cargar el stock.';
            avisoLista.replaceChildren(bloqueError(textoError(error), () => cargarLista()));
        } finally {
            if (!senal.aborted) {
                bloqueLista.removeAttribute('aria-busy');
            }
        }
    }

    /** Una talla cuenta como «poco stock» con la regla del sistema (ver la cabecera del archivo) */
    const esBaja = (t) => t.bajo_minimo && !t.producto.lanzamiento;

    function agrupar() {
        const zapatillas = new Map();
        for (const t of tallas) {
            if (!zapatillas.has(t.producto.id)) {
                zapatillas.set(t.producto.id, { producto: t.producto, tallas: [] });
            }
            zapatillas.get(t.producto.id).tallas.push(t);
        }
        return [...zapatillas.values()];
    }

    function pintarLista() {
        const todas = agrupar();
        const visibles = params.poco === '1' ? todas.filter((z) => z.tallas.some(esBaja)) : todas;
        const pares = visibles.reduce((s, z) => s + z.tallas.reduce((t, v) => t + v.stock, 0), 0);
        const bajas = todas.reduce((s, z) => s + z.tallas.filter(esBaja).length, 0);
        const filtrado = params.buscar || params.categoria ? ' con estos filtros' : '';
        linea.textContent = `${contar(visibles.length, 'zapatilla', 'zapatillas')}${filtrado} · ${contar(pares, 'par', 'pares')}`
            + ` · ${contar(bajas, 'talla está', 'tallas están')} en el mínimo o por debajo`;

        if (visibles.length === 0) {
            const hayFiltros = params.buscar || params.categoria || params.poco;
            cuerpoTabla.replaceChildren(filaVacia(COLUMNAS, params.poco === '1' && todas.length
                ? 'Ninguna zapatilla tiene tallas en el mínimo con estos filtros.'
                : 'No hay zapatillas con estos filtros.', hayFiltros ? 'stock' : null));
            pie.textContent = '';
            return;
        }

        cuerpoTabla.replaceChildren(...visibles.map(filaZapatilla));
        pie.textContent = 'Pulsa una talla para ajustar su stock o ver sus movimientos. Los lanzamientos no cuentan como poco stock hasta su fecha de salida.';
        marcarAbierta();
    }

    function filaZapatilla({ producto, tallas: suyas }) {
        const fila = crear('tr');
        // El flex va en un div interior: un <td> con display:flex deja de ser celda y descuadra la fila
        const zapatilla = crear('td');
        const contenido = crear('div', 'celda-zapatilla');
        const textos = crear('div', 'celda-zapatilla__textos');
        const nombre = crear('span', 'celda-zapatilla__nombre', producto.nombre);
        textos.append(nombre, crear('span', 'tabla__sub', producto.colorway));
        const marcas = crear('span', 'celda-zapatilla__marcas');
        if (producto.lanzamiento) {
            marcas.append(etiqueta(`Lanzamiento ${fechaDia(producto.fecha_lanzamiento)}`, 'lanzamiento'));
        }
        if (!producto.activo) {
            marcas.append(etiqueta('Desactivada', 'apagada'));
        }
        if (marcas.hasChildNodes()) {
            textos.append(marcas);
        }
        contenido.append(fotoProducto(producto.codigo_marca), textos);
        zapatilla.append(contenido);

        const celdaTallas = crear('td', 'celda-tallas');
        const lista = crear('ul', 'tallas-inv');
        lista.setAttribute('aria-label', `Tallas de ${producto.nombre} ${producto.colorway}`);
        for (const t of suyas) {
            const elemento = crear('li');
            const enlace = crear('a', 'talla-inv');
            enlace.href = hashDe('stock', { ...params, talla: t.variante_id });
            enlace.dataset.variante = t.variante_id;
            if (esBaja(t)) {
                enlace.classList.add('talla-inv--baja');
            }
            if (!t.activo) {
                enlace.classList.add('talla-inv--apagada');
            }
            enlace.append(crear('span', 'talla-inv__talla', formatoTalla(t.talla)), crear('span', 'talla-inv__stock num', numero(t.stock)));
            const estado = [esBaja(t) ? 'en el mínimo o por debajo' : '', t.activo ? '' : 'talla desactivada'].filter(Boolean).join(', ');
            enlace.setAttribute('aria-label', `Talla ${formatoTalla(t.talla)}: ${contar(t.stock, 'par', 'pares')}${estado ? `, ${estado}` : ''}`);
            elemento.append(enlace);
            lista.append(elemento);
        }
        celdaTallas.append(lista);

        const total = suyas.reduce((s, t) => s + t.stock, 0);
        fila.append(
            zapatilla,
            crear('td', 'tabla__codigo', producto.codigo_marca),
            crear('td', '', producto.categoria.nombre),
            celdaTallas,
            crear('td', 'num celda-total', numero(total)),
        );
        return fila;
    }

    function marcarAbierta() {
        for (const enlace of cuerpoTabla.querySelectorAll('.talla-inv')) {
            const abierta = Number(enlace.dataset.variante) === tallaAbierta;
            enlace.classList.toggle('talla-inv--abierta', abierta);
            if (abierta) {
                enlace.setAttribute('aria-current', 'true');
            } else {
                enlace.removeAttribute('aria-current');
            }
        }
    }

    // --- Panel lateral de una talla ------------------------------------------------

    const partes = { cabeza: null, resumen: null, aviso: null, ajuste: null, historial: null };
    let movimientos = [];
    let paginacionMovimientos = null;

    function datosTalla() {
        return tallas.find((t) => t.variante_id === tallaAbierta) ?? null;
    }

    function abrirTalla(id) {
        tallaAbierta = id;
        movimientos = [];
        paginacionMovimientos = null;
        lateral.abrir();
        marcarAbierta();

        partes.resumen = crear('section', 'detalle__bloque resumen-talla');
        partes.aviso = crear('div', 'detalle__aviso');
        partes.aviso.setAttribute('role', 'alert');
        partes.ajuste = formularioAjuste();
        partes.historial = crear('section', 'detalle__bloque');
        pintarResumenTalla();
        // Primero lo que hay, después el ajuste y debajo de dónde viene esa cifra
        lateral.caja.replaceChildren(partes.cabeza, partes.resumen, partes.aviso, partes.ajuste, partes.historial);
        cargarMovimientos(1);
    }

    function pintarResumenTalla() {
        const t = datosTalla();
        const etiquetas = [];
        if (t?.producto.lanzamiento) {
            etiquetas.push(etiqueta(`Lanzamiento ${fechaDia(t.producto.fecha_lanzamiento)}`, 'lanzamiento'));
        }
        if (t && !t.activo) {
            etiquetas.push(etiqueta('Talla desactivada', 'apagada'));
        }
        const titulo = t ? `Talla ${formatoTalla(t.talla)}` : 'Talla';
        const meta = t ? `${t.producto.nombre} · ${t.producto.colorway} · ${t.producto.codigo_marca}` : '';
        const nueva = cabezaLateral(lateral, titulo, { etiquetas, meta, textoCerrar: 'Cerrar la talla' });
        partes.cabeza?.replaceWith(nueva);
        partes.cabeza = nueva;

        partes.resumen.replaceChildren();
        if (!t) {
            partes.resumen.append(crear('p', 'detalle__sin-pasos', 'Esta talla no está en la lista con los filtros actuales; su historial sigue abajo.'));
            return;
        }
        const datos = crear('dl', 'datos');
        const stock = crear('dd', 'num');
        stock.append(crear('span', esBaja(t) ? 'alerta' : '', contar(t.stock, 'par', 'pares')));
        datos.append(
            crear('dt', '', 'Stock'), stock,
            crear('dt', '', 'Mínimo'), crear('dd', 'num', `${contar(t.stock_minimo, 'par', 'pares')} (avisa al llegar a esta cifra)`),
            crear('dt', '', 'RFID'), crear('dd', 'tabla__codigo', t.rfid_tienda),
        );
        partes.resumen.append(crear('h3', 'detalle__subtitulo', 'Talla'), datos);
    }

    /** Ajuste manual: la diferencia (no el stock final) y un motivo obligatorio, como pide la API */
    function formularioAjuste() {
        const bloque = crear('section', 'detalle__acciones');
        bloque.setAttribute('aria-label', 'Ajustar el stock');
        const form = crear('form', 'formulario-inv formulario-inv--ajuste');
        form.noValidate = true;   // los errores se enseñan junto al campo, con el mismo texto que la API

        const entradaCantidad = crear('input', 'campo-texto num');
        Object.assign(entradaCantidad, { type: 'number', step: 1, min: -500, max: 500, inputMode: 'numeric', autocomplete: 'off', name: 'cantidad' });
        const cantidad = campoFormulario({
            id: 'ajuste-cantidad', etiqueta: 'Cantidad', control: entradaCantidad,
            ayuda: 'Positiva suma pares, negativa los resta', clase: 'campo-inv--corto',
        });
        const entradaMotivo = crear('input', 'campo-texto');
        Object.assign(entradaMotivo, { type: 'text', maxLength: 255, autocomplete: 'off', name: 'motivo' });
        const motivo = campoFormulario({
            id: 'ajuste-motivo', etiqueta: 'Motivo', control: entradaMotivo,
            ayuda: 'Queda en el historial: recuento, caja dañada, devolución…', clase: 'campo-inv--ancho',
        });

        const previa = crear('p', 'formulario-inv__previa num');
        previa.setAttribute('aria-live', 'polite');
        const guardar = crear('button', 'control control--primario', 'Guardar ajuste');
        guardar.type = 'submit';
        const fila = crear('div', 'paso__botones');
        fila.append(guardar, previa);

        form.append(crear('h3', 'detalle__subtitulo', 'Ajustar el stock'), cantidad.caja, motivo.caja, fila);
        const campos = { cantidad, motivo };

        entradaCantidad.addEventListener('input', () => {
            const t = datosTalla();
            const valor = Number(entradaCantidad.value);
            previa.textContent = t && Number.isInteger(valor) && valor !== 0
                ? `Quedarán ${contar(t.stock + valor, 'par', 'pares')}`
                : '';
        });

        form.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            partes.aviso.replaceChildren();
            const valor = Number(entradaCantidad.value);
            const texto = entradaMotivo.value.trim();

            // Las mismas reglas que la API, para no esperar a la respuesta en lo evidente
            const errores = {};
            if (entradaCantidad.value === '' || !Number.isInteger(valor) || valor < -500 || valor > 500) {
                errores.cantidad = 'Número entero entre -500 y 500';
            } else if (valor === 0) {
                errores.cantidad = 'Distinta de 0: positiva para sumar unidades, negativa para restarlas';
            }
            if (texto === '') {
                errores.motivo = 'Obligatorio: explica por qué cambia el stock';
            }
            if (Object.keys(errores).length) {
                mostrarErrores(campos, errores);
                return;
            }
            mostrarErrores(campos, {});

            guardar.disabled = true;
            form.setAttribute('aria-busy', 'true');
            try {
                const { datos } = await api(`/api/gestion/variantes/${tallaAbierta}/stock`, {
                    metodo: 'PATCH', cuerpo: { cantidad: valor, motivo: texto },
                });
                form.reset();
                previa.textContent = '';
                ctx.anunciar(`Talla ${formatoTalla(datos.talla)} de ${datos.producto.nombre}: ${conSigno(valor)}, quedan ${contar(datos.stock_resultante, 'par', 'pares')}`);
                cargarLista();
                cargarMovimientos(1);
                ctx.avisarCambio('stock');
            } catch (error) {
                if (sesionCaducada(error)) {
                    return;
                }
                if (error.estado === 400) {
                    const sueltos = mostrarErrores(campos, error.detalles?.errores);
                    if (sueltos.length) {
                        partes.aviso.replaceChildren(bloqueError(sueltos.join(' ')));
                    }
                } else if (error.estado === 409) {
                    const disponible = error.detalles?.stock_disponible;
                    partes.aviso.replaceChildren(bloqueError(`${error.message}: hay ${contar(disponible ?? 0, 'par', 'pares')} y quieres restar ${numero(Math.abs(valor))}.`));
                    cargarLista();
                } else {
                    partes.aviso.replaceChildren(bloqueError(textoError(error)));
                }
            } finally {
                guardar.disabled = false;
                form.removeAttribute('aria-busy');
            }
        });

        bloque.append(form);
        return bloque;
    }

    async function cargarMovimientos(pagina) {
        controlDetalle?.abort();
        controlDetalle = new AbortController();
        const senal = controlDetalle.signal;
        const id = tallaAbierta;
        if (pagina === 1 && movimientos.length === 0) {
            partes.historial.replaceChildren(crear('h3', 'detalle__subtitulo', 'Movimientos'), crear('p', 'detalle__cargando', 'Cargando los movimientos…'));
        }
        try {
            const respuesta = await api(`/api/gestion/variantes/${id}/movimientos?pagina=${pagina}&por_pagina=${MOVIMIENTOS_POR_PAGINA}`, { senal });
            movimientos = pagina === 1 ? respuesta.datos : [...movimientos, ...respuesta.datos];
            paginacionMovimientos = respuesta.paginacion;
            pintarMovimientos();
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            const mensaje = error.estado === 404 ? 'Esta talla no existe.' : textoError(error);
            partes.historial.replaceChildren(
                crear('h3', 'detalle__subtitulo', 'Movimientos'),
                bloqueError(mensaje, error.estado === 404 ? null : () => cargarMovimientos(pagina)),
            );
        }
    }

    function pintarMovimientos() {
        const cabeza = crear('div', 'bloque-inv__cabeza');
        cabeza.append(
            crear('h3', 'detalle__subtitulo', 'Movimientos'),
            crear('p', 'tarjeta__meta num', contar(paginacionMovimientos.total, 'movimiento', 'movimientos')),
        );
        if (movimientos.length === 0) {
            partes.historial.replaceChildren(cabeza, crear('p', 'detalle__sin-pasos', 'Esta talla todavía no tiene movimientos.'));
            return;
        }

        const tablaMov = crear('table', 'tabla tabla--compacta');
        tablaMov.innerHTML = '<caption class="oculto">Movimientos de la talla, del más reciente al más antiguo</caption>'
            + '<thead><tr><th scope="col">Fecha</th><th scope="col">Movimiento</th>'
            + '<th scope="col" class="num">Pares</th><th scope="col" class="num">Stock</th></tr></thead>';
        const cuerpo = crear('tbody');
        for (const m of movimientos) {
            const fila = crear('tr');
            const que = crear('td', 'celda-movimiento');
            que.append(crear('span', 'celda-movimiento__tipo', TIPOS_MOVIMIENTO[m.tipo] ?? m.tipo));
            // Las ventas y cancelaciones ya nombran su pedido en el motivo: no se repite
            const motivoMov = m.motivo ?? '';
            const detalle = m.pedido && !motivoMov.includes(m.pedido)
                ? [`Pedido ${m.pedido}`, motivoMov].filter(Boolean).join(' · ')
                : motivoMov;
            if (detalle) {
                que.append(crear('span', 'celda-movimiento__motivo', detalle));
            }
            if (m.usuario) {
                que.append(crear('span', 'tabla__sub', m.usuario.nombre));
            }
            fila.append(
                crear('td', 'tabla__fecha num', fechaCorta(m.fecha)),
                que,
                crear('td', `num ${m.cantidad > 0 ? 'cantidad-inv--entra' : 'cantidad-inv--sale'}`, conSigno(m.cantidad)),
                crear('td', 'num', numero(m.stock_resultante)),
            );
            cuerpo.append(fila);
        }
        tablaMov.append(cuerpo);
        const envoltorioMov = crear('div', 'tabla-desplazable');
        envoltorioMov.append(tablaMov);
        partes.historial.replaceChildren(cabeza, envoltorioMov);

        if (paginacionMovimientos.pagina < paginacionMovimientos.paginas) {
            const mas = crear('button', 'control control--texto', `Ver ${Math.min(MOVIMIENTOS_POR_PAGINA, paginacionMovimientos.total - movimientos.length)} más antiguos`);
            mas.type = 'button';
            mas.addEventListener('click', () => cargarMovimientos(paginacionMovimientos.pagina + 1));
            partes.historial.append(mas);
        }
    }

    // --- Arranque y cambios de la URL ----------------------------------------------

    function aplicarParams(nuevos) {
        const anteriores = params;
        params = limpiar(nuevos);
        pintarFiltros();

        if (anteriores.buscar !== params.buscar || anteriores.categoria !== params.categoria) {
            cargarLista();
        } else if (anteriores.poco !== params.poco && !primeraLista) {
            pintarLista();
        }
        const id = params.talla ? Number(params.talla) : null;
        if (id && id !== tallaAbierta) {
            abrirTalla(id);
        } else if (!id && lateral.elemento.open) {
            lateral.cerrar();
        }
    }

    // Un pedido cancelado o un albarán cargado en otra sección cambian el stock
    document.addEventListener('panel:cambio', (evento) => {
        if (evento.detail?.origen !== 'stock') {
            cargarLista();
        }
    }, { signal: ctx.senal });
    ctx.senal.addEventListener('abort', () => {
        controlLista?.abort();
        controlDetalle?.abort();
        window.clearTimeout(temporizador);
    });

    pintarFiltros();
    cargarLista();
    if (params.talla) {
        abrirTalla(Number(params.talla));
    }

    return { actualizar: aplicarParams };
}

/** Solo los parámetros que entiende la sección, sin valores vacíos */
function limpiar(entrada) {
    const salida = {};
    if (/^\d{1,9}$/.test(entrada.categoria ?? '')) {
        salida.categoria = entrada.categoria;
    }
    if (entrada.buscar) {
        salida.buscar = String(entrada.buscar).slice(0, 60);
    }
    if (entrada.poco === '1') {
        salida.poco = '1';
    }
    if (/^\d{1,9}$/.test(entrada.talla ?? '')) {
        salida.talla = entrada.talla;
    }
    return salida;
}
