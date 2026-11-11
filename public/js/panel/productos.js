/*
 * Sección «Productos» (solo administración, permiso productos.gestionar): el
 * catálogo interno con las zapatillas desactivadas incluidas, el alta y la
 * edición en el panel lateral y la desactivación (o reactivación) con
 * confirmación.
 *
 * API: GET y POST /api/gestion/productos, GET y PATCH /api/gestion/productos/{id}
 * y DELETE /api/gestion/productos/{id} (desactiva, no borra). Reactivar es un
 * PATCH con {"activo": true}. Las categorías y los colores salen de
 * GET /api/catalogo/filtros, que los lista todos.
 *
 * El código de Nike no se edita (identifica la zapatilla y de él salen la URL,
 * la foto y el RFID) y la fecha de lanzamiento solo se consulta: la API no la
 * acepta en el alta ni en la edición.
 *
 * Filtros y zapatilla abierta en el hash: #productos?activo=false&producto=12
 * (producto=nueva abre el alta).
 */

import { api, crear, esFutura, precio, talla as formatoTalla } from '../api.js';
import { bloqueError, cabeceraSeccion, contar, hashDe, numero, sesionCaducada, textoError } from './comun.js';
import {
    cabezaLateral, campoFormulario, etiqueta, fechaDia, filaVacia, filasEsqueleto, fotoProducto, lateral as crearLateral,
    mostrarErrores, pintarPaginas,
} from './piezas.js';

const POR_PAGINA = 20;
const ESPERA_BUSQUEDA = 300;
const COLUMNAS = 9;
const NUEVA = 'nueva';

const FILTROS_ACTIVO = [
    ['', 'Todas'],
    ['true', 'Activas'],
    ['false', 'Desactivadas'],
];

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let controlLista = null;
    let controlDetalle = null;
    let temporizador = 0;
    let primeraLista = true;
    let abierto = null;          // id del producto del panel lateral, o NUEVA
    let valores = null;          // { categorias, colores } para los desplegables

    // --- Estructura ------------------------------------------------------------

    const { cabecera, linea, acciones } = cabeceraSeccion('Productos');
    linea.textContent = 'Cargando el catálogo…';
    const nueva = crear('a', 'control control--primario', 'Nueva zapatilla');
    nueva.href = hashDe('productos', { ...params, producto: NUEVA });
    acciones.append(nueva);

    const barra = crear('div', 'filtros');
    const pestanas = crear('nav', 'pestanas');
    pestanas.setAttribute('aria-label', 'Filtrar por estado');
    const enlacesActivo = new Map();
    for (const [valor, texto] of FILTROS_ACTIVO) {
        const enlace = crear('a', 'pestana');
        enlace.append(crear('span', '', texto), crear('span', 'pestana__cifra num', ''));
        pestanas.append(enlace);
        enlacesActivo.set(valor, enlace);
    }
    const busqueda = crear('form', 'filtros__busqueda');
    busqueda.setAttribute('role', 'search');
    const etiquetaBuscar = crear('label', 'oculto', 'Buscar por nombre, colorway o código');
    etiquetaBuscar.htmlFor = 'productos-buscar';
    const campoBuscar = crear('input', 'campo-texto');
    Object.assign(campoBuscar, {
        id: 'productos-buscar', type: 'search', maxLength: 60, placeholder: 'Nombre, colorway o código', autocomplete: 'off',
    });
    busqueda.append(etiquetaBuscar, campoBuscar);
    barra.append(pestanas, busqueda);

    const bloqueLista = crear('section', 'tarjeta tarjeta--lista');
    bloqueLista.setAttribute('aria-labelledby', 'panel-titulo');
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla tabla--inv tabla--productos');
    tabla.innerHTML = '<caption class="oculto">Zapatillas del catálogo, de la más reciente a la más antigua</caption>'
        + '<thead><tr><th scope="col"><span class="oculto">Foto</span></th><th scope="col">Código</th><th scope="col">Zapatilla</th>'
        + '<th scope="col">Categoría</th><th scope="col" class="num">Precio</th><th scope="col">Personalizable</th>'
        + '<th scope="col">Estado</th><th scope="col">Lanzamiento</th><th scope="col" class="num">Stock</th></tr></thead>';
    const cuerpoTabla = crear('tbody');
    tabla.append(cuerpoTabla);
    envoltorio.append(tabla);
    const avisoLista = crear('div', 'tarjeta__aviso');
    const paginas = crear('nav', 'paginas');
    paginas.setAttribute('aria-label', 'Páginas de la lista');
    bloqueLista.append(avisoLista, envoltorio, paginas);

    const lateral = crearLateral(ctx, () => {
        abierto = null;
        controlDetalle?.abort();
        if (params.producto) {
            irConFiltros({ producto: undefined, pagina: params.pagina });
        }
        marcarAbierta();
    });

    contenedor.append(cabecera, barra, bloqueLista, lateral.elemento);

    // --- Filtros -----------------------------------------------------------------

    function irConFiltros(cambios, opciones = { reemplazar: true }) {
        ctx.irA('productos', { ...params, pagina: undefined, ...cambios }, opciones);
    }

    busqueda.addEventListener('submit', (evento) => {
        evento.preventDefault();
        window.clearTimeout(temporizador);
        irConFiltros({ buscar: campoBuscar.value.trim(), producto: undefined });
    });
    campoBuscar.addEventListener('input', () => {
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(() => irConFiltros({ buscar: campoBuscar.value.trim(), producto: undefined }), ESPERA_BUSQUEDA);
    });

    cuerpoTabla.addEventListener('click', (evento) => {
        const fila = evento.target.closest('tr[data-id]');
        if (fila && !evento.target.closest('a')) {
            fila.querySelector('a')?.click();
        }
    });

    function pintarFiltros() {
        for (const [valor, enlace] of enlacesActivo) {
            enlace.href = hashDe('productos', { ...params, activo: valor, pagina: undefined, producto: undefined });
            if ((params.activo ?? '') === valor) {
                enlace.setAttribute('aria-current', 'true');
            } else {
                enlace.removeAttribute('aria-current');
            }
        }
        if (document.activeElement !== campoBuscar) {
            campoBuscar.value = params.buscar ?? '';
        }
        nueva.href = hashDe('productos', { ...params, producto: NUEVA });
    }

    // --- Lista -------------------------------------------------------------------

    function consulta(extra) {
        const datos = { buscar: params.buscar, ...extra };
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
            // La lista y cuántas hay en cada pestaña con la misma búsqueda
            const [lista, ...cuentas] = await Promise.all([
                api(`/api/gestion/productos?${consulta({ activo: params.activo, pagina: params.pagina ?? 1, por_pagina: POR_PAGINA })}`, { senal }),
                ...FILTROS_ACTIVO.map(([activo]) => api(`/api/gestion/productos?${consulta({ activo, por_pagina: 1 })}`, { senal })),
            ]);
            primeraLista = false;
            avisoLista.replaceChildren();
            const totales = cuentas.map((c) => c.paginacion.total);
            FILTROS_ACTIVO.forEach(([valor], i) => {
                enlacesActivo.get(valor).querySelector('.pestana__cifra').textContent = numero(totales[i]);
            });
            const [todas, activas, desactivadas] = totales;
            const filtrado = params.buscar ? ' con esta búsqueda' : '';
            linea.textContent = `${contar(todas, 'zapatilla', 'zapatillas')}${filtrado} · ${contar(activas, 'a la venta', 'a la venta')}`
                + ` · ${contar(desactivadas, 'desactivada', 'desactivadas')}`;
            pintarFilas(lista.datos);
            pintarPaginas(paginas, lista.paginacion, (pagina) => hashDe('productos', { ...params, pagina, producto: undefined }));
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            if (primeraLista) {
                cuerpoTabla.replaceChildren();
            }
            linea.textContent = 'No se ha podido cargar el catálogo.';
            avisoLista.replaceChildren(bloqueError(textoError(error), () => cargarLista()));
        } finally {
            if (!senal.aborted) {
                bloqueLista.removeAttribute('aria-busy');
            }
        }
    }

    function pintarFilas(productos) {
        if (productos.length === 0) {
            cuerpoTabla.replaceChildren(filaVacia(COLUMNAS, 'No hay zapatillas con estos filtros.', params.buscar || params.activo ? 'productos' : null));
            return;
        }
        cuerpoTabla.replaceChildren(...productos.map((p) => {
            const fila = crear('tr');
            fila.dataset.id = p.id;
            if (!p.activo) {
                fila.classList.add('fila-inv--apagada');
            }
            const foto = crear('td', 'celda-foto');
            foto.append(fotoProducto(p.codigo_marca, 36));

            const codigo = crear('td');
            const enlace = crear('a', 'tabla__enlace tabla__codigo', p.codigo_marca);
            enlace.href = hashDe('productos', { ...params, producto: p.id });
            enlace.setAttribute('aria-label', `${p.codigo_marca}, editar ${p.nombre}`);
            codigo.append(enlace);

            const nombre = crear('td', 'tabla__cliente');
            nombre.append(crear('span', '', p.nombre), crear('span', 'tabla__sub', p.colorway));

            const estado = crear('td');
            estado.append(p.activo ? etiqueta('Activa', 'activa') : etiqueta('Desactivada', 'apagada'));

            const lanzamiento = crear('td', 'tabla__fecha num');
            if (p.fecha_lanzamiento && esFutura(p.fecha_lanzamiento)) {
                lanzamiento.append(etiqueta(`Lanzamiento ${fechaDia(p.fecha_lanzamiento)}`, 'lanzamiento'));
            } else {
                lanzamiento.textContent = p.fecha_lanzamiento ? fechaDia(p.fecha_lanzamiento) : '—';
            }

            fila.append(
                foto,
                codigo,
                nombre,
                crear('td', '', p.categoria.nombre),
                crear('td', 'num', precio(p.precio)),
                crear('td', p.personalizable ? '' : 'texto-tenue', p.personalizable ? 'Sí' : 'No'),
                estado,
                lanzamiento,
                crear('td', 'num', `${numero(p.stock_total)}`),
            );
            return fila;
        }));
        marcarAbierta();
    }

    function marcarAbierta() {
        for (const fila of cuerpoTabla.querySelectorAll('tr[data-id]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.id === String(abierto));
        }
    }

    // --- Panel lateral: alta y edición -----------------------------------------------

    async function cargarValores(senal) {
        if (!valores) {
            const { datos } = await api('/api/catalogo/filtros', { senal });
            valores = { categorias: datos.categorias, colores: datos.colores };
        }
        return valores;
    }

    async function abrir(id) {
        controlDetalle?.abort();
        controlDetalle = new AbortController();
        const senal = controlDetalle.signal;
        abierto = id;
        lateral.abrir();
        marcarAbierta();
        const titulo = id === NUEVA ? 'Nueva zapatilla' : 'Zapatilla';
        lateral.caja.replaceChildren(
            cabezaLateral(lateral, titulo, { textoCerrar: 'Cerrar sin guardar' }),
            crear('p', 'detalle__cargando', id === NUEVA ? 'Preparando el formulario…' : 'Cargando la zapatilla…'),
        );
        try {
            const [listas, producto] = await Promise.all([
                cargarValores(senal),
                id === NUEVA ? null : api(`/api/gestion/productos/${id}`, { senal }).then((r) => r.datos),
            ]);
            pintarLateral(producto, listas);
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            const mensaje = error.estado === 404 ? 'Esta zapatilla no existe.' : textoError(error);
            lateral.caja.replaceChildren(
                cabezaLateral(lateral, titulo, { textoCerrar: 'Cerrar' }),
                bloqueError(mensaje, error.estado === 404 ? null : () => abrir(id)),
            );
        }
    }

    /** producto null = alta */
    function pintarLateral(producto, listas) {
        const etiquetas = [];
        if (producto) {
            etiquetas.push(producto.activo ? etiqueta('Activa', 'activa') : etiqueta('Desactivada', 'apagada'));
            if (producto.fecha_lanzamiento && esFutura(producto.fecha_lanzamiento)) {
                etiquetas.push(etiqueta(`Lanzamiento ${fechaDia(producto.fecha_lanzamiento)}`, 'lanzamiento'));
            }
        }
        const cabeza = cabezaLateral(lateral, producto ? producto.nombre : 'Nueva zapatilla', {
            etiquetas,
            meta: producto
                ? `${producto.codigo_marca} · ${contar(producto.stock_total, 'par', 'pares')} en ${contar(producto.num_tallas, 'talla', 'tallas')}`
                : 'Se da de alta con stock 0: las unidades entran con un albarán o un ajuste de stock.',
            textoCerrar: 'Cerrar sin guardar',
        });
        const aviso = crear('div', 'detalle__aviso');
        aviso.setAttribute('role', 'alert');
        const piezas = [cabeza, aviso, formulario(producto, listas, aviso)];
        if (producto) {
            piezas.push(bloqueTallas(producto), bloqueEstado(producto, aviso));
        }
        lateral.caja.replaceChildren(...piezas);
    }

    function formulario(producto, { categorias, colores }, aviso) {
        const form = crear('form', 'detalle__bloque formulario-inv');
        form.noValidate = true;
        const campos = {};
        const filas = crear('div', 'formulario-inv__rejilla');

        const texto = (nombre, maximo, extra = {}) => {
            const control = crear('input', 'campo-texto');
            Object.assign(control, { type: 'text', maxLength: maximo, autocomplete: 'off', name: nombre, ...extra });
            return control;
        };
        const desplegable = (nombre, opciones, actual) => {
            const control = crear('select', 'campo-texto campo-select');
            control.name = nombre;
            if (actual === undefined) {
                const vacia = crear('option', '', 'Elige…');
                vacia.value = '';
                control.append(vacia);
            }
            for (const opcion of opciones) {
                const elemento = crear('option', '', opcion.nombre);
                elemento.value = String(opcion.id);
                control.append(elemento);
            }
            control.value = actual === undefined ? '' : String(actual);
            return control;
        };

        if (!producto) {
            const codigo = texto('codigo_marca', 10, { spellcheck: false, placeholder: 'DD1391-102' });
            codigo.setAttribute('autocapitalize', 'characters');
            campos.codigo_marca = campoFormulario({
                id: 'producto-codigo', etiqueta: 'Código de Nike', control: codigo,
                ayuda: 'Style code de nike.com (6 letras o cifras, guion y 3 cifras). No se podrá cambiar.',
            });
        }
        campos.nombre = campoFormulario({ id: 'producto-nombre', etiqueta: 'Nombre', control: texto('nombre', 140, { value: producto?.nombre ?? '' }), clase: 'campo-inv--ancho' });
        campos.colorway = campoFormulario({ id: 'producto-colorway', etiqueta: 'Colorway', control: texto('colorway', 100, { value: producto?.colorway ?? '', placeholder: 'White/University Blue' }), clase: 'campo-inv--ancho' });
        campos.precio = campoFormulario({
            id: 'producto-precio', etiqueta: 'Precio (€)',
            control: texto('precio', 8, { inputMode: 'decimal', value: producto ? String(producto.precio).replace('.', ',') : '', placeholder: '119,99' }),
            ayuda: 'Los pedidos ya hechos conservan su precio', clase: 'campo-inv--corto',
        });
        campos.categoria_id = campoFormulario({ id: 'producto-categoria', etiqueta: 'Categoría', control: desplegable('categoria_id', categorias, producto?.categoria.id) });
        campos.color_id = campoFormulario({ id: 'producto-color', etiqueta: 'Color', control: desplegable('color_id', colores, producto?.color.id) });

        const descripcion = crear('textarea', 'campo-texto campo-area');
        Object.assign(descripcion, { name: 'descripcion', maxLength: 2000, rows: 3, value: producto?.descripcion ?? '' });
        campos.descripcion = campoFormulario({ id: 'producto-descripcion', etiqueta: 'Descripción (opcional)', control: descripcion, clase: 'campo-inv--ancho' });

        if (!producto) {
            campos.tallas = campoFormulario({
                id: 'producto-tallas', etiqueta: 'Tallas (opcional)',
                control: texto('tallas', 200, { placeholder: '40 41 42,5', inputMode: 'decimal' }),
                ayuda: 'Separadas por espacios (42,5 es media talla); de 34 a 50', clase: 'campo-inv--ancho',
            });
        }

        // Casilla: en una fila propia, con su explicación
        const personalizable = crear('input');
        personalizable.type = 'checkbox';
        personalizable.checked = producto?.personalizable ?? false;
        const casilla = crear('label', 'casilla');
        casilla.append(personalizable, crear('span', '', 'Admite el nombre bordado en el talón'));
        const errorPersonalizable = crear('p', 'campo-inv__error');
        errorPersonalizable.id = 'producto-personalizable-error';
        personalizable.setAttribute('aria-describedby', errorPersonalizable.id);
        const cajaCasilla = crear('div', 'campo-inv campo-inv--ancho');
        cajaCasilla.append(casilla, errorPersonalizable);
        campos.personalizable = { caja: cajaCasilla, control: personalizable, error: errorPersonalizable };

        // Orden de la rejilla de dos columnas: en el alta, código y precio comparten fila;
        // categoría y color van juntos; la casilla, antes de la descripción y las tallas
        const orden = producto
            ? ['nombre', 'colorway', 'categoria_id', 'color_id', 'precio', 'personalizable', 'descripcion']
            : ['codigo_marca', 'precio', 'nombre', 'colorway', 'categoria_id', 'color_id', 'personalizable', 'descripcion', 'tallas'];
        filas.append(...orden.map((clave) => campos[clave].caja));

        const guardar = crear('button', 'control control--primario', producto ? 'Guardar cambios' : 'Dar de alta');
        guardar.type = 'submit';
        const botones = crear('div', 'paso__botones');
        botones.append(guardar);
        const tituloForm = crear('h3', 'detalle__subtitulo', producto ? 'Datos' : 'Datos de la zapatilla');
        form.append(tituloForm, filas, botones);

        function leer() {
            const datos = {
                nombre: campos.nombre.control.value.trim(),
                colorway: campos.colorway.control.value.trim(),
                precio: campos.precio.control.value.trim().replace(',', '.'),
                categoria_id: campos.categoria_id.control.value ? Number(campos.categoria_id.control.value) : '',
                color_id: campos.color_id.control.value ? Number(campos.color_id.control.value) : '',
                descripcion: campos.descripcion.control.value.trim() || null,
                personalizable: personalizable.checked,
            };
            if (!producto) {
                datos.codigo_marca = campos.codigo_marca.control.value.trim().toUpperCase();
                const tallas = campos.tallas.control.value.trim();
                // «42,5» es una talla: la coma decimal se respeta y se separa por espacios o «;»
                datos.tallas = tallas === '' ? [] : tallas.split(/[\s;]+/).filter(Boolean).map((t) => {
                    const valor = Number(t.replace(',', '.'));
                    return Number.isNaN(valor) ? t : valor;
                });
            }
            return datos;
        }

        form.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            aviso.replaceChildren();
            const datos = leer();
            let cuerpo = datos;
            if (producto) {
                // Solo lo que cambia: la API audita cada campo cambiado
                const actual = {
                    nombre: producto.nombre,
                    colorway: producto.colorway,
                    precio: producto.precio,
                    categoria_id: producto.categoria.id,
                    color_id: producto.color.id,
                    descripcion: producto.descripcion || null,
                    personalizable: producto.personalizable,
                };
                cuerpo = Object.fromEntries(Object.entries(datos).filter(([clave, valor]) => (
                    clave === 'precio' ? Number(valor) !== actual.precio || valor === '' : valor !== actual[clave]
                )));
                if (Object.keys(cuerpo).length === 0) {
                    mostrarErrores(campos, {});
                    aviso.replaceChildren(crear('p', 'nota-inv nota-inv--caja', 'No has cambiado nada.'));
                    return;
                }
            }

            guardar.disabled = true;
            form.setAttribute('aria-busy', 'true');
            try {
                const { datos: guardado } = producto
                    ? await api(`/api/gestion/productos/${producto.id}`, { metodo: 'PATCH', cuerpo })
                    : await api('/api/gestion/productos', { metodo: 'POST', cuerpo });
                ctx.anunciar(producto ? `${guardado.codigo_marca}: cambios guardados` : `${guardado.codigo_marca} dada de alta`);
                cargarLista();
                ctx.avisarCambio('productos');
                if (producto) {
                    pintarLateral(guardado, valores);
                    lateral.caja.querySelector('#lateral-titulo')?.focus();
                } else {
                    // El alta pasa a ser la edición de la zapatilla creada
                    abierto = guardado.id;
                    ctx.irA('productos', { ...params, producto: guardado.id }, { reemplazar: true });
                    pintarLateral(guardado, valores);
                    lateral.caja.querySelector('#lateral-titulo')?.focus();
                }
            } catch (error) {
                if (sesionCaducada(error)) {
                    return;
                }
                if (error.estado === 400 && error.detalles?.errores) {
                    const sueltos = mostrarErrores(campos, error.detalles.errores);
                    if (sueltos.length) {
                        aviso.replaceChildren(bloqueError(sueltos.join(' ')));
                    }
                } else if (error.estado === 409) {
                    // Código o URL repetidos: el motivo va en el campo que lo provoca
                    const clave = error.detalles?.codigo_marca ? 'codigo_marca' : 'nombre';
                    mostrarErrores(campos, { [clave]: error.message });
                } else {
                    aviso.replaceChildren(bloqueError(textoError(error)));
                }
            } finally {
                guardar.disabled = false;
                form.removeAttribute('aria-busy');
            }
        });

        return form;
    }

    function bloqueTallas(producto) {
        const bloque = crear('section', 'detalle__bloque');
        bloque.append(crear('h3', 'detalle__subtitulo', `Tallas (${numero(producto.variantes.length)})`));
        if (producto.variantes.length === 0) {
            bloque.append(crear('p', 'detalle__sin-pasos', 'Sin tallas: entran con el primer albarán que las traiga.'));
            return bloque;
        }
        const tablaTallas = crear('table', 'tabla tabla--compacta');
        tablaTallas.innerHTML = '<caption class="oculto">Tallas con su stock y su etiqueta RFID</caption>'
            + '<thead><tr><th scope="col" class="num">Talla</th><th scope="col" class="num">Stock</th>'
            + '<th scope="col" class="num">Mínimo</th><th scope="col">RFID</th></tr></thead>';
        const cuerpo = crear('tbody');
        for (const v of producto.variantes) {
            const fila = crear('tr');
            if (!v.activo) {
                fila.classList.add('fila-inv--apagada');
            }
            const stock = crear('td', 'num');
            stock.append(crear('span', v.stock <= v.stock_minimo ? 'alerta' : '', numero(v.stock)));
            const rfid = crear('td', 'tabla__codigo', v.rfid_tienda);
            if (!v.activo) {
                rfid.append(crear('span', 'tabla__sub', 'Talla desactivada'));
            }
            fila.append(crear('td', 'num', formatoTalla(v.talla)), stock, crear('td', 'num', numero(v.stock_minimo)), rfid);
            cuerpo.append(fila);
        }
        tablaTallas.append(cuerpo);
        const envoltorioTallas = crear('div', 'tabla-desplazable');
        envoltorioTallas.append(tablaTallas);
        const enlaceStock = crear('a', 'control control--texto', 'Ajustar el stock en Stock');
        enlaceStock.href = hashDe('stock', { buscar: producto.codigo_marca });
        bloque.append(envoltorioTallas, enlaceStock);
        return bloque;
    }

    /** Desactivar o reactivar, siempre con un paso de confirmación */
    function bloqueEstado(producto, aviso) {
        const bloque = crear('section', 'detalle__bloque estado-inv');
        bloque.append(crear('h3', 'detalle__subtitulo', 'Venta en la tienda'));
        const explicacion = producto.activo
            ? 'Activa: sale en el catálogo y se puede comprar.'
            : 'Desactivada: no sale en el catálogo. Sus pedidos, su stock y su historial se conservan.';
        bloque.append(crear('p', 'estado-inv__texto', explicacion));

        const boton = crear('button', producto.activo ? 'control control--peligro' : 'control', producto.activo ? 'Desactivar zapatilla' : 'Reactivar zapatilla');
        boton.type = 'button';
        const confirmacion = crear('div', 'detalle__formulario');
        boton.addEventListener('click', () => {
            confirmacion.replaceChildren(pasoConfirmar(producto, aviso, boton));
            confirmacion.querySelector('button[type="submit"]')?.focus();
        });
        bloque.append(boton, confirmacion);
        return bloque;
    }

    function pasoConfirmar(producto, aviso, botonOrigen) {
        const desactivar = producto.activo;
        const caja = crear('form', desactivar ? 'paso paso--peligro' : 'paso');
        caja.append(crear('p', 'paso__texto', desactivar
            ? `¿Desactivar ${producto.nombre} (${producto.codigo_marca})? Deja de venderse ya; ${contar(producto.stock_total, 'par se queda', 'pares se quedan')} en el almacén.`
            : `¿Reactivar ${producto.nombre} (${producto.codigo_marca})? Vuelve al catálogo con ${contar(producto.stock_total, 'par', 'pares')}.`));
        const fila = crear('div', 'paso__botones');
        const si = crear('button', desactivar ? 'control control--peligro-lleno' : 'control control--primario', desactivar ? 'Sí, desactivarla' : 'Sí, reactivarla');
        si.type = 'submit';
        const no = crear('button', 'control control--texto', 'No, dejarla así');
        no.type = 'button';
        no.addEventListener('click', () => {
            caja.remove();
            botonOrigen.focus();
        });
        fila.append(si, no);
        caja.append(fila);

        caja.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            si.disabled = true;
            aviso.replaceChildren();
            try {
                if (desactivar) {
                    await api(`/api/gestion/productos/${producto.id}`, { metodo: 'DELETE' });
                } else {
                    await api(`/api/gestion/productos/${producto.id}`, { metodo: 'PATCH', cuerpo: { activo: true } });
                }
                const { datos } = await api(`/api/gestion/productos/${producto.id}`);
                ctx.anunciar(`${producto.codigo_marca}: ${desactivar ? 'desactivada' : 'reactivada'}`);
                pintarLateral(datos, valores);
                lateral.caja.querySelector('#lateral-titulo')?.focus();
                cargarLista();
                ctx.avisarCambio('productos');
            } catch (error) {
                if (sesionCaducada(error)) {
                    return;
                }
                si.disabled = false;
                aviso.replaceChildren(bloqueError(textoError(error)));
            }
        });
        return caja;
    }

    // --- Arranque y cambios de la URL ----------------------------------------------

    function aplicarParams(nuevos) {
        const anteriores = params;
        params = limpiar(nuevos);
        pintarFiltros();
        if (['activo', 'buscar', 'pagina'].some((k) => anteriores[k] !== params[k])) {
            cargarLista();
        }
        const destino = params.producto === NUEVA ? NUEVA : (params.producto ? Number(params.producto) : null);
        if (destino && destino !== abierto) {
            abrir(destino);
        } else if (!destino && lateral.elemento.open) {
            lateral.cerrar();
        }
    }

    // Un albarán puede dar de alta zapatillas o sumar stock
    document.addEventListener('panel:cambio', (evento) => {
        if (evento.detail?.origen !== 'productos') {
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
    if (params.producto) {
        abrir(params.producto === NUEVA ? NUEVA : Number(params.producto));
    }

    return { actualizar: aplicarParams };
}

function limpiar(entrada) {
    const salida = {};
    if (entrada.activo === 'true' || entrada.activo === 'false') {
        salida.activo = entrada.activo;
    }
    if (entrada.buscar) {
        salida.buscar = String(entrada.buscar).slice(0, 60);
    }
    const pagina = Number.parseInt(entrada.pagina, 10);
    if (pagina > 1) {
        salida.pagina = String(pagina);
    }
    if (entrada.producto === NUEVA || /^\d{1,9}$/.test(entrada.producto ?? '')) {
        salida.producto = entrada.producto;
    }
    return salida;
}
