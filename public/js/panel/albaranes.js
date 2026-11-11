/*
 * Sección «Albaranes» (almacén y administración, permiso albaranes.ver): la
 * lista de los albaranes del proveedor ya cargados y, en el panel lateral, las
 * tallas que entraron con cada uno. Administración ve además el formulario de
 * carga (permiso productos.gestionar).
 *
 * API: GET /api/gestion/albaranes?pagina, GET /api/gestion/albaranes/detalle?referencia
 * y POST /api/gestion/albaranes?referencia (cuerpo text/csv).
 *
 * El archivo no se guarda: lo que queda de cada carga es su resumen en la
 * auditoría y sus movimientos de stock, y de ahí sale todo lo que se ve aquí.
 * Un albarán entra entero o no entra; si tiene errores, se enseñan agrupados
 * por fila del archivo para poder corregirlo y volver a enviarlo.
 *
 * Lista y albarán abierto en el hash: #albaranes?pagina=2&ref=ALB-2026-0042
 */

import { api, crear, talla as formatoTalla } from '../api.js';
import { bloqueError, cabeceraSeccion, contar, fechaCorta, hashDe, numero, sesionCaducada, textoError } from './comun.js';
import {
    cabezaLateral, campo, conSigno, crearLateral, enviarTexto, etiqueta, filaVacia, filasEsqueleto, pintarPaginas,
} from './inventario-comun.js';

const POR_PAGINA = 20;
const COLUMNAS = 7;
const TAMANO_MAXIMO = 1_048_576;   // el mismo límite de cuerpo que aplica la API (1 MB)
// El mismo patrón que AlbaranServicio::PATRON_REFERENCIA
const PATRON_REFERENCIA = /^[A-Za-z0-9/_-]{1,50}$/;
const COLUMNAS_CSV = 'codigo_marca, nombre, colorway, categoria, color, precio, talla, cantidad';

/** Nombre legible de cada columna del CSV para los errores por fila */
const NOMBRE_COLUMNA = {
    codigo_marca: 'código',
    nombre: 'nombre',
    colorway: 'colorway',
    categoria: 'categoría',
    color: 'color',
    precio: 'precio',
    talla: 'talla',
    cantidad: 'cantidad',
};

export function montar(contenedor, ctx) {
    let params = limpiar(ctx.params);
    let controlLista = null;
    let controlDetalle = null;
    let primeraLista = true;
    let abierto = null;   // referencia del albarán del panel lateral

    // --- Estructura ------------------------------------------------------------

    const { cabecera, linea } = cabeceraSeccion('Albaranes');
    linea.textContent = 'Cargando los albaranes…';

    const piezas = [cabecera];
    if (ctx.puede('productos.gestionar')) {
        piezas.push(bloqueCarga());
    }

    const bloqueLista = crear('section', 'tarjeta tarjeta--lista');
    bloqueLista.setAttribute('aria-labelledby', 'albaranes-lista-titulo');
    const cabezaLista = crear('div', 'tarjeta__cabeza');
    const tituloLista = crear('h2', 'tarjeta__titulo', 'Albaranes cargados');
    tituloLista.id = 'albaranes-lista-titulo';
    cabezaLista.append(tituloLista, crear('p', 'tarjeta__meta', 'del más reciente al más antiguo'));
    const envoltorio = crear('div', 'tabla-desplazable');
    const tabla = crear('table', 'tabla tabla--inv tabla--albaranes');
    tabla.innerHTML = '<caption class="oculto">Albaranes cargados, del más reciente al más antiguo</caption>'
        + '<thead><tr><th scope="col">Referencia</th><th scope="col">Cargado</th><th scope="col">Por</th>'
        + '<th scope="col" class="num">Filas</th><th scope="col" class="num">Pares</th>'
        + '<th scope="col">Zapatillas</th><th scope="col" class="num">Tallas nuevas</th></tr></thead>';
    const cuerpoTabla = crear('tbody');
    tabla.append(cuerpoTabla);
    envoltorio.append(tabla);
    const avisoLista = crear('div', 'tarjeta__aviso');
    const paginas = crear('nav', 'paginas');
    paginas.setAttribute('aria-label', 'Páginas de la lista');
    bloqueLista.append(cabezaLista, avisoLista, envoltorio, paginas);

    const lateral = crearLateral(ctx, () => {
        abierto = null;
        controlDetalle?.abort();
        if (params.ref) {
            ctx.irA('albaranes', { ...params, ref: undefined }, { reemplazar: true });
        }
        marcarAbierta();
    });

    piezas.push(bloqueLista, lateral.elemento);
    contenedor.append(...piezas);

    // Toda la fila abre el albarán, no solo la referencia (que es el enlace accesible)
    cuerpoTabla.addEventListener('click', (evento) => {
        const fila = evento.target.closest('tr[data-ref]');
        if (fila && !evento.target.closest('a')) {
            fila.querySelector('a')?.click();
        }
    });

    // --- Carga de un albarán (solo administración) -----------------------------------

    function bloqueCarga() {
        const caja = crear('section', 'tarjeta carga-inv');
        caja.setAttribute('aria-labelledby', 'carga-titulo');
        const cabeza = crear('div', 'tarjeta__cabeza');
        const titulo = crear('h2', 'tarjeta__titulo', 'Cargar un albarán');
        titulo.id = 'carga-titulo';
        cabeza.append(titulo, crear('p', 'tarjeta__meta', 'entra entero o no entra: con una fila mal no se carga nada'));

        const form = crear('form', 'formulario-inv formulario-inv--carga');
        form.noValidate = true;
        const entradaRef = crear('input', 'campo-texto');
        Object.assign(entradaRef, { type: 'text', maxLength: 50, autocomplete: 'off', spellcheck: false, name: 'referencia', placeholder: 'ALB-2026-0042' });
        entradaRef.setAttribute('autocapitalize', 'characters');
        const referencia = campo({
            id: 'carga-referencia', etiqueta: 'Referencia del proveedor', control: entradaRef,
            ayuda: 'Hasta 50 caracteres: letras, números, / _ y -',
        });
        const entradaArchivo = crear('input', 'campo-archivo');
        Object.assign(entradaArchivo, { type: 'file', accept: '.csv,text/csv', name: 'archivo' });
        const archivo = campo({
            id: 'carga-archivo', etiqueta: 'Archivo CSV', control: entradaArchivo,
            ayuda: 'UTF-8, separado por comas o por punto y coma (el de Excel)',
        });
        const enviar = crear('button', 'control control--primario', 'Cargar albarán');
        enviar.type = 'submit';
        const filaBotones = crear('div', 'formulario-inv__enviar');
        filaBotones.append(enviar);

        const ayuda = crear('details', 'ayuda-inv');
        ayuda.append(crear('summary', '', 'Formato del archivo'));
        const textoAyuda = crear('div', 'ayuda-inv__cuerpo');
        const cabeceraCsv = crear('p');
        cabeceraCsv.append(crear('span', '', 'Primera línea, la cabecera: '), crear('code', '', COLUMNAS_CSV));
        const ejemplo = crear('pre', 'ayuda-inv__ejemplo');
        ejemplo.textContent = 'codigo_marca,nombre,colorway,categoria,color,precio,talla,cantidad\n'
            + 'CW2288-111,,,,,,42,6\n'
            + 'DD1391-102,Nike Dunk Low Retro,White/University Blue,Lifestyle,Azul,119.99,42,4';
        textoAyuda.append(
            cabeceraCsv,
            crear('p', '', 'Una fila por talla. Código, talla (34 a 50, admite medias: 42.5 o 42,5) y cantidad (1 a 1000) van siempre.'),
            crear('p', '', 'Nombre, colorway, categoría, color y precio solo hacen falta en la primera fila de una zapatilla nueva; si ya existe, se ignoran y solo se suma el stock.'),
            crear('p', '', 'Las zapatillas nuevas se dan de alta sin bordado; las desactivadas suman stock pero siguen sin venderse.'),
            ejemplo,
        );
        ayuda.append(textoAyuda);

        const resultado = crear('div', 'carga-inv__resultado');
        resultado.setAttribute('role', 'status');
        resultado.setAttribute('aria-live', 'polite');

        const camposForm = crear('div', 'formulario-inv__campos');
        camposForm.append(referencia.caja, archivo.caja, filaBotones);
        form.append(camposForm, ayuda);
        caja.append(cabeza, form, resultado);

        const campos = { referencia, archivo, cabecera: archivo };

        form.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            resultado.replaceChildren();
            const ref = entradaRef.value.trim();
            const fichero = entradaArchivo.files?.[0];

            const errores = {};
            if (ref === '') {
                errores.referencia = 'Escribe la referencia del albarán: sale en los movimientos de stock';
            } else if (!PATRON_REFERENCIA.test(ref)) {
                errores.referencia = 'Hasta 50 caracteres: letras, números, / _ y -';
            }
            if (!fichero) {
                errores.archivo = 'Elige el archivo CSV del albarán';
            } else if (fichero.size > TAMANO_MAXIMO) {
                errores.archivo = 'El archivo pasa de 1 MB: divídelo en varios albaranes';
            }
            if (Object.keys(errores).length) {
                mostrarErroresCarga(errores);
                return;
            }
            mostrarErroresCarga({});

            enviar.disabled = true;
            form.setAttribute('aria-busy', 'true');
            resultado.append(crear('p', 'carga-inv__enviando', `Cargando ${fichero.name}…`));
            try {
                const texto = await fichero.text();
                const { datos } = await enviarTexto(`/api/gestion/albaranes?referencia=${encodeURIComponent(ref)}`, texto, 'text/csv');
                form.reset();
                resultado.replaceChildren(resumenCarga(datos));
                ctx.anunciar(`${datos.referencia}: ${contar(datos.unidades, 'par', 'pares')} al stock`);
                ctx.avisarCambio('albaranes');
                if (params.pagina) {
                    ctx.irA('albaranes', { ...params, pagina: undefined }, { reemplazar: true });
                } else {
                    cargarLista();
                }
            } catch (error) {
                if (sesionCaducada(error)) {
                    return;
                }
                resultado.replaceChildren();
                if (error.estado === 400) {
                    pintarErroresAlbaran(error, resultado);
                } else if (error.estado === 413) {
                    mostrarErroresCarga({ archivo: 'El archivo es demasiado grande para la tienda: divídelo en varios albaranes' });
                } else if (error.estado === 415) {
                    mostrarErroresCarga({ archivo: 'El archivo tiene que ser un CSV de texto' });
                } else {
                    resultado.append(bloqueError(textoError(error)));
                }
            } finally {
                enviar.disabled = false;
                form.removeAttribute('aria-busy');
            }
        });

        function mostrarErroresCarga(errores) {
            for (const { control, error } of [referencia, archivo]) {
                error.textContent = '';
                control.removeAttribute('aria-invalid');
            }
            let primero = null;
            for (const [clave, motivo] of Object.entries(errores)) {
                const destino = campos[clave];
                if (destino) {
                    destino.error.textContent = motivo;
                    destino.control.setAttribute('aria-invalid', 'true');
                    primero ??= destino.control;
                }
            }
            primero?.focus();
        }

        /**
         * 400 del albarán: las claves «fila.5.precio» se agrupan por fila (la
         * línea del archivo, con la cabecera como línea 1); las demás (referencia,
         * cabecera, archivo) van junto a su campo o arriba.
         */
        function pintarErroresAlbaran(error, destino) {
            const errores = error.detalles?.errores ?? {};
            const porFila = new Map();
            const generales = [];
            const deCampo = {};
            for (const [clave, motivo] of Object.entries(errores)) {
                const partes = clave.split('.');
                if (partes[0] === 'fila') {
                    const numeroFila = Number(partes[1]);
                    if (!porFila.has(numeroFila)) {
                        porFila.set(numeroFila, []);
                    }
                    porFila.get(numeroFila).push([partes[2] ?? null, motivo]);
                } else if (clave === 'referencia') {
                    deCampo.referencia = motivo;
                } else {
                    generales.push(motivo);
                }
            }
            mostrarErroresCarga(deCampo);

            const caja = crear('div', 'errores-inv');
            caja.setAttribute('role', 'alert');
            const total = porFila.size;
            caja.append(crear('p', 'errores-inv__titulo', total
                ? `${error.message}. ${contar(total, 'fila tiene', 'filas tienen')} errores:`
                : `${error.message}.`));
            for (const texto of generales) {
                caja.append(crear('p', 'errores-inv__general', texto));
            }
            if (total) {
                const tablaErrores = crear('table', 'tabla tabla--compacta');
                tablaErrores.innerHTML = '<caption class="oculto">Errores por fila del archivo</caption>'
                    + '<thead><tr><th scope="col" class="num">Línea</th><th scope="col">Columna</th><th scope="col">Qué falla</th></tr></thead>';
                const cuerpo = crear('tbody');
                for (const numeroFila of [...porFila.keys()].sort((a, b) => a - b)) {
                    porFila.get(numeroFila).forEach(([columna, motivo], i) => {
                        const fila = crear('tr');
                        // El número de línea solo en la primera de sus errores: se lee como un grupo
                        fila.append(
                            crear('td', 'num', i === 0 ? numero(numeroFila) : ''),
                            crear('td', '', columna ? (NOMBRE_COLUMNA[columna] ?? columna) : 'toda la fila'),
                            crear('td', '', motivo),
                        );
                        cuerpo.append(fila);
                    });
                }
                tablaErrores.append(cuerpo);
                const envoltorioErrores = crear('div', 'tabla-desplazable');
                envoltorioErrores.append(tablaErrores);
                caja.append(envoltorioErrores, crear('p', 'errores-inv__pie', 'Corrige el archivo y vuelve a cargarlo con la misma referencia: no se ha sumado ninguna unidad.'));
            }
            destino.append(caja);
            caja.scrollIntoView({ block: 'nearest' });
        }

        return caja;
    }

    /** Resumen tras cargar: lo que el admin necesita comprobar contra el papel del proveedor */
    function resumenCarga(datos) {
        const caja = crear('div', 'exito-inv');
        const titulo = crear('p', 'exito-inv__titulo');
        titulo.append(
            crear('span', '', `${datos.referencia} cargado: `),
            crear('span', 'num', `${contar(datos.filas, 'fila', 'filas')} · ${contar(datos.unidades, 'par', 'pares')} al stock`),
        );
        const lista = crear('dl', 'datos datos--anchos');
        lista.append(...filasResumen(datos));
        const ver = crear('a', 'control', 'Ver las tallas que han entrado');
        ver.href = hashDe('albaranes', { ref: datos.referencia });
        caja.append(titulo, lista);
        if (datos.aviso) {
            caja.append(crear('p', 'exito-inv__aviso', 'Hay zapatillas desactivadas en el albarán: su stock se ha sumado, pero siguen sin venderse hasta que se reactiven en Productos.'));
        }
        caja.append(ver);
        return caja;
    }

    // --- Lista -------------------------------------------------------------------

    async function cargarLista() {
        controlLista?.abort();
        controlLista = new AbortController();
        const senal = controlLista.signal;
        bloqueLista.setAttribute('aria-busy', 'true');
        if (primeraLista) {
            cuerpoTabla.replaceChildren(...filasEsqueleto(COLUMNAS, 4));
        }
        try {
            const respuesta = await api(`/api/gestion/albaranes?pagina=${params.pagina ?? 1}&por_pagina=${POR_PAGINA}`, { senal });
            primeraLista = false;
            avisoLista.replaceChildren();
            pintarFilas(respuesta.datos);
            pintarPaginas(paginas, respuesta.paginacion, (pagina) => hashDe('albaranes', { pagina }));
            const { total } = respuesta.paginacion;
            const ultimo = respuesta.datos[0];
            linea.textContent = total === 0
                ? 'Todavía no se ha cargado ningún albarán.'
                : `${contar(total, 'albarán cargado', 'albaranes cargados')}`
                    + (ultimo && !params.pagina ? ` · el último, ${ultimo.referencia} el ${fechaCorta(ultimo.fecha)}` : '');
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            if (primeraLista) {
                cuerpoTabla.replaceChildren();
            }
            linea.textContent = 'No se han podido cargar los albaranes.';
            avisoLista.replaceChildren(bloqueError(textoError(error), () => cargarLista()));
        } finally {
            if (!senal.aborted) {
                bloqueLista.removeAttribute('aria-busy');
            }
        }
    }

    function pintarFilas(albaranes) {
        if (albaranes.length === 0) {
            cuerpoTabla.replaceChildren(filaVacia(COLUMNAS, params.pagina ? 'No hay albaranes en esta página.' : 'Todavía no se ha cargado ningún albarán.'));
            return;
        }
        cuerpoTabla.replaceChildren(...albaranes.map((albaran) => {
            const fila = crear('tr');
            fila.dataset.ref = albaran.referencia;
            const ref = crear('td');
            const enlace = crear('a', 'tabla__enlace', albaran.referencia);
            enlace.href = hashDe('albaranes', { ...params, ref: albaran.referencia });
            ref.append(enlace);

            const zapatillas = crear('td', 'celda-cambios');
            const nuevas = albaran.productos_nuevos.length;
            const actualizadas = albaran.productos_actualizados.length;
            zapatillas.append(crear('span', '', [
                nuevas ? contar(nuevas, 'nueva', 'nuevas') : '',
                actualizadas ? contar(actualizadas, 'actualizada', 'actualizadas') : '',
            ].filter(Boolean).join(' · ') || '—'));
            if (albaran.desactivados.length) {
                zapatillas.append(crear('span', 'tabla__sub', `${contar(albaran.desactivados.length, 'desactivada', 'desactivadas')}`));
            }

            const por = crear('td', 'tabla__cliente');
            por.append(crear('span', '', albaran.usuario?.nombre ?? 'Usuario borrado'));
            fila.append(
                ref,
                crear('td', 'tabla__fecha num', fechaCorta(albaran.fecha)),
                por,
                crear('td', 'num', numero(albaran.filas)),
                crear('td', 'num', numero(albaran.unidades)),
                zapatillas,
                crear('td', 'num', numero(albaran.tallas_nuevas)),
            );
            return fila;
        }));
        marcarAbierta();
    }

    function marcarAbierta() {
        for (const fila of cuerpoTabla.querySelectorAll('tr[data-ref]')) {
            fila.classList.toggle('tabla__fila--abierta', fila.dataset.ref === abierto);
        }
    }

    // --- Detalle -------------------------------------------------------------------

    async function abrirDetalle(referencia) {
        controlDetalle?.abort();
        controlDetalle = new AbortController();
        const senal = controlDetalle.signal;
        abierto = referencia;
        lateral.abrir();
        marcarAbierta();
        lateral.caja.replaceChildren(
            cabezaLateral(lateral, referencia, { textoCerrar: 'Cerrar el albarán' }),
            crear('p', 'detalle__cargando', 'Cargando el albarán…'),
        );
        try {
            const { datos } = await api(`/api/gestion/albaranes/detalle?referencia=${encodeURIComponent(referencia)}`, { senal });
            pintarDetalle(datos);
        } catch (error) {
            if (error.name === 'AbortError' || sesionCaducada(error)) {
                return;
            }
            const mensaje = error.estado === 404 ? `No se ha cargado ningún albarán con la referencia ${referencia}.` : textoError(error);
            lateral.caja.replaceChildren(
                cabezaLateral(lateral, referencia, { textoCerrar: 'Cerrar el albarán' }),
                bloqueError(mensaje, error.estado === 404 ? null : () => abrirDetalle(referencia)),
            );
        }
    }

    function pintarDetalle(albaran) {
        const quien = albaran.usuario?.nombre ?? 'un usuario borrado';
        const cabeza = cabezaLateral(lateral, albaran.referencia, {
            meta: `Cargado el ${fechaCorta(albaran.fecha)} por ${quien} · ${contar(albaran.unidades, 'par', 'pares')}`,
            textoCerrar: 'Cerrar el albarán',
        });

        const resumen = crear('section', 'detalle__bloque');
        const datos = crear('dl', 'datos datos--anchos');
        datos.append(...filasResumen(albaran));
        resumen.append(crear('h3', 'detalle__subtitulo', 'Resumen'), datos);
        if (albaran.cargas > 1) {
            resumen.append(crear('p', 'nota-inv', `Esta referencia se ha cargado ${numero(albaran.cargas)} veces; aquí se ve la última carga.`));
        }

        const bloqueTallas = crear('section', 'detalle__bloque');
        bloqueTallas.append(crear('h3', 'detalle__subtitulo', `Tallas que entraron (${numero(albaran.tallas.length)})`));
        if (albaran.tallas.length === 0) {
            bloqueTallas.append(crear('p', 'detalle__sin-pasos', 'No quedan movimientos de este albarán.'));
        } else {
            const nuevas = new Set(albaran.productos_nuevos);
            const desactivadas = new Set(albaran.desactivados);
            const tablaTallas = crear('table', 'tabla tabla--compacta');
            tablaTallas.innerHTML = '<caption class="oculto">Tallas del albarán con la cantidad que entró y el stock que dejó</caption>'
                + '<thead><tr><th scope="col">Zapatilla</th><th scope="col" class="num">Talla</th>'
                + '<th scope="col" class="num">Entran</th><th scope="col" class="num">Stock</th></tr></thead>';
            const cuerpo = crear('tbody');
            let anterior = null;
            for (const t of albaran.tallas) {
                const fila = crear('tr');
                const zapatilla = crear('td', 'celda-movimiento');
                // El nombre solo en la primera talla de cada zapatilla: se lee como un grupo
                if (t.producto.codigo_marca !== anterior) {
                    fila.classList.add('fila-inv--grupo');
                    zapatilla.append(crear('span', 'celda-movimiento__tipo', t.producto.nombre));
                    const sub = crear('span', 'tabla__sub', `${t.producto.codigo_marca} · ${t.producto.colorway}`);
                    zapatilla.append(sub);
                    const marcas = crear('span', 'celda-zapatilla__marcas');
                    if (nuevas.has(t.producto.codigo_marca)) {
                        marcas.append(etiqueta('Nueva', 'nueva'));
                    }
                    if (desactivadas.has(t.producto.codigo_marca)) {
                        marcas.append(etiqueta('Desactivada', 'apagada'));
                    }
                    if (marcas.hasChildNodes()) {
                        zapatilla.append(marcas);
                    }
                }
                anterior = t.producto.codigo_marca;
                fila.append(
                    zapatilla,
                    crear('td', 'num', formatoTalla(t.talla)),
                    crear('td', 'num cantidad-inv--entra', conSigno(t.cantidad)),
                    crear('td', 'num', numero(t.stock_resultante)),
                );
                cuerpo.append(fila);
            }
            tablaTallas.append(cuerpo);
            const envoltorioTallas = crear('div', 'tabla-desplazable');
            envoltorioTallas.append(tablaTallas);
            bloqueTallas.append(envoltorioTallas, crear('p', 'nota-inv', 'Stock: el que dejó la entrada en ese momento, no el de hoy.'));
        }

        lateral.caja.replaceChildren(cabeza, resumen, bloqueTallas);
    }

    // --- Arranque y cambios de la URL ----------------------------------------------

    function aplicarParams(nuevos) {
        const anteriores = params;
        params = limpiar(nuevos);
        if (anteriores.pagina !== params.pagina) {
            cargarLista();
        }
        if (params.ref && params.ref !== abierto) {
            abrirDetalle(params.ref);
        } else if (!params.ref && lateral.elemento.open) {
            lateral.cerrar();
        }
    }

    ctx.senal.addEventListener('abort', () => {
        controlLista?.abort();
        controlDetalle?.abort();
    });

    cargarLista();
    if (params.ref) {
        abrirDetalle(params.ref);
    }

    return { actualizar: aplicarParams };
}

/** Las cifras del resumen, iguales tras cargar y en el detalle */
function filasResumen(datos) {
    const lista = (codigos) => (codigos.length ? codigos.join(', ') : '—');
    const filas = [
        ['Filas', numero(datos.filas)],
        ['Pares', numero(datos.unidades)],
        ['Nuevas', lista(datos.productos_nuevos)],
        ['Actualizadas', lista(datos.productos_actualizados)],
        ['Tallas nuevas', numero(datos.tallas_nuevas)],
        ['Desactivadas', lista(datos.desactivados)],
    ];
    return filas.flatMap(([nombre, valor]) => [crear('dt', '', nombre), crear('dd', valor === '—' ? 'datos__vacio' : 'num', valor)]);
}

function limpiar(entrada) {
    const salida = {};
    const pagina = Number.parseInt(entrada.pagina, 10);
    if (pagina > 1) {
        salida.pagina = String(pagina);
    }
    if (entrada.ref && PATRON_REFERENCIA.test(entrada.ref)) {
        salida.ref = entrada.ref;
    }
    return salida;
}
