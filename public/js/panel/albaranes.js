/*
 * Sección «Albaranes» (almacén y administración, permiso albaranes.ver): la
 * lista de los albaranes del proveedor ya cargados y, en el panel lateral, las
 * tallas que entraron con cada uno. Administración ve además el formulario de
 * carga (permiso productos.gestionar).
 *
 * API: GET /api/gestion/albaranes?pagina, GET /api/gestion/albaranes/detalle?referencia,
 * POST /api/gestion/albaranes?referencia (cuerpo text/csv) y, solo administración,
 * GET /api/gestion/albaranes/avisos.
 *
 * Una referencia ya cargada se rechaza con un 409: el panel enseña los datos de
 * la carga anterior y deja «Cancelar» o «Cargar igualmente» (con motivo, que
 * se envía como ?aceptar_repetida=1&motivo=). Los intentos rechazados salen en
 * el bloque «Avisos» de arriba, y las cargas repetidas, con la etiqueta
 * «Repetido». Todo eso es solo para administración: el almacén no carga.
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
    cabezaLateral, campoFormulario as campo, conSigno, lateral as crearLateral, enviarTexto, etiqueta, filaVacia,
    filasEsqueleto, pintarPaginas,
} from './piezas.js';

const POR_PAGINA = 20;
const COLUMNAS = 7;
const TAMANO_MAXIMO = 1_048_576;   // el mismo límite de cuerpo que aplica la API (1 MB)
// El mismo patrón que AlbaranServicio::PATRON_REFERENCIA
const PATRON_REFERENCIA = /^[A-Za-z0-9/_-]{1,50}$/;
const COLUMNAS_CSV = 'codigo_marca, nombre, colorway, categoria, color, precio, talla, cantidad';
const AVISOS_POR_PAGINA = 5;
// Los mismos límites que AlbaranServicio::MOTIVO_MINIMO y MOTIVO_MAXIMO
const MOTIVO_MINIMO = 5;
const MOTIVO_MAXIMO = 300;

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
    let controlAvisos = null;
    const esAdmin = ctx.puede('productos.gestionar');

    // --- Estructura ------------------------------------------------------------

    const { cabecera, linea } = cabeceraSeccion('Albaranes');
    linea.textContent = 'Cargando los albaranes…';

    const piezas = [cabecera];
    // Los avisos y la carga son de administración: el almacén solo consulta
    const avisos = esAdmin ? bloqueAvisos() : null;
    if (esAdmin) {
        piezas.push(avisos.elemento, bloqueCarga());
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

            // El archivo se lee como UTF-8 estricto: fichero.text() cambiaría los bytes no
            // válidos por «�» y un CSV de Excel en Windows-1252 entraría con «Ni�o»
            const texto = decodificarUtf8(await fichero.arrayBuffer());
            if (texto === null) {
                mostrarErroresCarga({ archivo: 'El archivo no está guardado como UTF-8' });
                resultado.replaceChildren(errorCodificacion(fichero.name));
                return;
            }
            await enviarAlbaran({ ref, texto, nombre: fichero.name });
        });

        /**
         * Envía el albarán. Con «motivo», acepta cargar una referencia que ya se
         * cargó (?aceptar_repetida=1). «campoMotivo» es donde se enseña un error
         * del motivo sin perder el aviso de la referencia repetida.
         */
        async function enviarAlbaran({ ref, texto, nombre, motivo = null, campoMotivo = null }) {
            enviar.disabled = true;
            form.setAttribute('aria-busy', 'true');
            if (motivo === null) {
                resultado.replaceChildren(crear('p', 'carga-inv__enviando', `Cargando ${nombre}…`));
            }
            let ruta = `/api/gestion/albaranes?referencia=${encodeURIComponent(ref)}`;
            if (motivo !== null) {
                ruta += `&aceptar_repetida=1&motivo=${encodeURIComponent(motivo)}`;
            }
            try {
                const { datos } = await enviarTexto(ruta, texto, 'text/csv');
                form.reset();
                resultado.replaceChildren(resumenCarga(datos));
                ctx.anunciar(`${datos.referencia}: ${contar(datos.unidades, 'par', 'pares')} al stock`);
                ctx.avisarCambio('albaranes');
                if (datos.repetida) {
                    cargarAvisos();   // el aviso pasa a «Cargada después»
                }
                if (params.pagina) {
                    ctx.irA('albaranes', { ...params, pagina: undefined }, { reemplazar: true });
                } else {
                    cargarLista();
                }
            } catch (error) {
                if (sesionCaducada(error)) {
                    return;
                }
                const errorMotivo = error.detalles?.errores?.motivo;
                if (error.estado === 400 && errorMotivo && campoMotivo) {
                    campoMotivo.error.textContent = errorMotivo;
                    campoMotivo.control.setAttribute('aria-invalid', 'true');
                    campoMotivo.control.focus();
                    return;
                }
                resultado.replaceChildren();
                if (error.estado === 409 && error.detalles?.carga_anterior) {
                    resultado.append(avisoRepetida(error.detalles, { ref, texto, nombre }));
                    cargarAvisos();   // el intento rechazado ya es un aviso más
                } else if (error.estado === 400) {
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
        }

        /** El CSV no es UTF-8: no se envía nada y se explica cómo guardarlo bien */
        function errorCodificacion(nombre) {
            const caja = crear('div', 'errores-inv');
            caja.setAttribute('role', 'alert');
            caja.append(
                crear('p', 'errores-inv__titulo', `${nombre} no está en UTF-8: no se ha enviado.`),
                crear('p', 'errores-inv__general', 'Guarda el CSV como UTF-8 y vuelve a elegirlo; si no, las tildes y las eñes (Niño) llegarían estropeadas.'),
                crear('p', 'errores-inv__pie', 'En Excel: Archivo › Guardar como › Tipo «CSV UTF-8 (delimitado por comas) (*.csv)». En LibreOffice: Guardar como CSV y, en «Juego de caracteres», Unicode (UTF-8).'),
            );
            return caja;
        }

        /**
         * 409: la referencia ya se cargó. Se enseña la carga anterior y dos
         * salidas: cancelar (no pasa nada) o cargar igualmente, que pide el
         * motivo y una confirmación porque las unidades se suman otra vez.
         */
        function avisoRepetida(detalles, envio) {
            const anterior = detalles.carga_anterior;
            const caja = crear('div', 'errores-inv');
            caja.setAttribute('role', 'alert');
            const titulo = crear('p', 'errores-inv__titulo', `La referencia ${detalles.referencia} ya se cargó: no se ha sumado ninguna unidad.`);
            titulo.tabIndex = -1;
            const datos = crear('dl', 'datos datos--anchos');
            const filas = [
                ['Cargada el', fechaCorta(anterior.fecha)],
                ['Por', anterior.usuario?.nombre ?? 'Usuario borrado'],
                ['Pares', numero(anterior.unidades)],
                ['Filas', numero(anterior.filas)],
            ];
            if (detalles.cargas > 1) {
                filas.push(['Veces cargada', numero(detalles.cargas)]);
            }
            datos.append(...filas.flatMap(([nombreDato, valor]) => [crear('dt', '', nombreDato), crear('dd', 'num', valor)]));
            const explicacion = crear('p', 'errores-inv__pie', 'Si es el mismo albarán, cancela: volver a cargarlo duplicaría el stock. Cárgalo solo si de verdad han llegado otra vez esas unidades.');

            const botones = crear('div', 'paso__botones');
            const cancelar = crear('button', 'control', 'Cancelar');
            cancelar.type = 'button';
            const igualmente = crear('button', 'control control--peligro', 'Cargar igualmente');
            igualmente.type = 'button';
            botones.append(cancelar, igualmente);
            caja.append(titulo, datos, explicacion, botones);

            cancelar.addEventListener('click', () => {
                resultado.replaceChildren();
                ctx.anunciar('Carga cancelada: no se ha sumado nada');
                entradaRef.focus();
            });
            igualmente.addEventListener('click', () => {
                botones.remove();
                caja.append(confirmacionRepetida(envio, () => {
                    caja.append(botones);
                    igualmente.focus();
                }));
            });
            requestAnimationFrame(() => titulo.focus());
            return caja;
        }

        /** Motivo obligatorio y confirmación explícita antes de repetir la carga */
        function confirmacionRepetida(envio, alVolver) {
            const paso = crear('form', 'paso paso--peligro');
            paso.noValidate = true;
            paso.append(crear('p', 'paso__texto', `¿Cargar ${envio.ref} otra vez? Las unidades de ${envio.nombre} se sumarán de nuevo al stock.`));
            const id = 'carga-motivo-repetida';
            const etiquetaMotivo = crear('label', 'paso__etiqueta', 'Motivo (obligatorio, queda en la auditoría)');
            etiquetaMotivo.htmlFor = id;
            const motivo = crear('textarea', 'campo-texto campo-area');
            Object.assign(motivo, { id, maxLength: MOTIVO_MAXIMO, rows: 2, placeholder: 'Ej.: el proveedor mandó una segunda entrega con el mismo número' });
            const error = crear('p', 'paso__error');
            error.id = `${id}-error`;
            motivo.setAttribute('aria-describedby', error.id);
            // El error se quita al escribir: si no, sigue pidiendo un motivo que ya está escrito
            motivo.addEventListener('input', () => {
                error.textContent = '';
                motivo.removeAttribute('aria-invalid');
            });
            const fila = crear('div', 'paso__botones');
            const si = crear('button', 'control control--peligro-lleno', 'Sí, cargar igualmente');
            si.type = 'submit';
            const no = crear('button', 'control control--texto', 'No, volver');
            no.type = 'button';
            no.addEventListener('click', () => {
                paso.remove();
                alVolver();
            });
            fila.append(si, no);
            paso.append(etiquetaMotivo, motivo, error, fila);

            paso.addEventListener('submit', async (evento) => {
                evento.preventDefault();
                const texto = motivo.value.trim();
                error.textContent = '';
                motivo.removeAttribute('aria-invalid');
                if (texto.length < MOTIVO_MINIMO) {
                    error.textContent = texto === ''
                        ? 'Escribe por qué se vuelve a cargar esta referencia'
                        : `Al menos ${MOTIVO_MINIMO} caracteres`;
                    motivo.setAttribute('aria-invalid', 'true');
                    motivo.focus();
                    return;
                }
                si.disabled = true;
                try {
                    await enviarAlbaran({ ...envio, motivo: texto, campoMotivo: { control: motivo, error } });
                } finally {
                    si.disabled = false;
                }
            });
            requestAnimationFrame(() => motivo.focus());
            return paso;
        }

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
        if (datos.repetida) {
            caja.append(crear('p', 'exito-inv__aviso', `Carga repetida aceptada. Motivo: ${datos.motivo_repetida}`));
        }
        if (datos.desactivados.length) {
            caja.append(crear('p', 'exito-inv__aviso', 'Hay zapatillas desactivadas en el albarán: su stock se ha sumado, pero siguen sin venderse hasta que se reactiven en Productos.'));
        }
        if (datos.tallas_desactivadas?.length) {
            caja.append(crear('p', 'exito-inv__aviso', `${contar(datos.tallas_desactivadas.length, 'talla desactivada ha', 'tallas desactivadas han')} recibido stock (${listaTallas(datos.tallas_desactivadas)}): siguen sin venderse hasta que se reactiven.`));
        }
        caja.append(ver);
        return caja;
    }

    // --- Avisos de referencias repetidas (solo administración) ---------------------

    /**
     * Los últimos intentos de cargar una referencia que ya estaba cargada. Se
     * esconde si no hay ninguno: un bloque vacío arriba solo distrae.
     */
    function bloqueAvisos() {
        const elemento = crear('section', 'tarjeta tarjeta--lista');
        elemento.setAttribute('aria-labelledby', 'albaranes-avisos-titulo');
        elemento.hidden = true;
        const cabeza = crear('div', 'tarjeta__cabeza');
        const titulo = crear('h2', 'tarjeta__titulo', 'Avisos');
        titulo.id = 'albaranes-avisos-titulo';
        const meta = crear('p', 'tarjeta__meta', 'referencias repetidas que no se han cargado');
        cabeza.append(titulo, meta);
        const envoltorioAvisos = crear('div', 'tabla-desplazable');
        const tablaAvisos = crear('table', 'tabla tabla--inv');
        tablaAvisos.innerHTML = '<caption class="oculto">Intentos rechazados de cargar una referencia ya cargada, del más reciente al más antiguo</caption>'
            + '<thead><tr><th scope="col">Intento</th><th scope="col">Referencia</th><th scope="col">Por</th>'
            + '<th scope="col" class="num">Pares</th><th scope="col">Estado</th></tr></thead>';
        const cuerpo = crear('tbody');
        tablaAvisos.append(cuerpo);
        envoltorioAvisos.append(tablaAvisos);
        elemento.append(cabeza, envoltorioAvisos);
        return { elemento, cuerpo, meta };
    }

    async function cargarAvisos() {
        if (!avisos) {
            return;
        }
        controlAvisos?.abort();
        controlAvisos = new AbortController();
        try {
            const respuesta = await api(`/api/gestion/albaranes/avisos?por_pagina=${AVISOS_POR_PAGINA}`, { senal: controlAvisos.signal });
            pintarAvisos(respuesta.datos, respuesta.paginacion.total);
        } catch (error) {
            // Los avisos son un extra: si fallan, la sección sigue sirviendo sin ellos
            if (error.name !== 'AbortError') {
                sesionCaducada(error);
            }
        }
    }

    function pintarAvisos(lista, total) {
        avisos.elemento.hidden = lista.length === 0;
        const pendientes = lista.filter((aviso) => !aviso.aceptada_despues).length;
        // Una referencia ya cargada que se intentó cargar otra vez: no sumó nada
        const estado = pendientes
            ? `${contar(pendientes, 'referencia repetida rechazada', 'referencias repetidas rechazadas')}`
            : 'referencias repetidas, ya cargadas después';
        avisos.meta.textContent = total > lista.length
            ? `${estado} · los ${numero(lista.length)} intentos más recientes de ${numero(total)}`
            : estado;
        avisos.cuerpo.replaceChildren(...lista.map((aviso) => {
            const fila = crear('tr');
            const ref = crear('td');
            const enlace = crear('a', 'tabla__enlace', aviso.referencia);
            enlace.href = hashDe('albaranes', { ...params, ref: aviso.referencia });
            ref.append(enlace);
            const estado = crear('td');
            estado.append(aviso.aceptada_despues ? etiqueta('Cargada después', 'activa') : etiqueta('Rechazada', 'nueva'));
            fila.append(
                crear('td', 'tabla__fecha num', fechaCorta(aviso.fecha)),
                ref,
                crear('td', 'tabla__cliente', aviso.usuario?.nombre ?? 'Usuario borrado'),
                crear('td', 'num', numero(aviso.unidades)),
                estado,
            );
            return fila;
        }));
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
            if (esAdmin && albaran.repetida) {
                ref.append(' ', etiqueta('Repetido', 'nueva'));
            }

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
            etiquetas: esAdmin && albaran.repetida ? [etiqueta('Repetido', 'nueva')] : [],
            meta: `Cargado el ${fechaCorta(albaran.fecha)} por ${quien} · ${contar(albaran.unidades, 'par', 'pares')}`,
            textoCerrar: 'Cerrar el albarán',
        });

        const resumen = crear('section', 'detalle__bloque');
        const datos = crear('dl', 'datos datos--anchos');
        datos.append(...filasResumen(albaran));
        resumen.append(crear('h3', 'detalle__subtitulo', 'Resumen'), datos);
        if (esAdmin && albaran.repetida) {
            resumen.append(crear('p', 'nota-inv nota-inv--caja', `Carga repetida aceptada. Motivo: ${albaran.motivo_repetida}`));
        }
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
        controlAvisos?.abort();
    });

    cargarLista();
    cargarAvisos();
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
        ['Tallas desactivadas', datos.tallas_desactivadas?.length ? listaTallas(datos.tallas_desactivadas) : '—'],
    ];
    return filas.flatMap(([nombre, valor]) => [crear('dt', '', nombre), crear('dd', valor === '—' ? 'datos__vacio' : 'num', valor)]);
}

/** «CW2288-111 42, CW2288-111 42.5»: tallas desactivadas que recibieron stock */
function listaTallas(tallas) {
    return tallas.map((t) => `${t.codigo_marca} ${formatoTalla(t.talla)}`).join(', ');
}

/**
 * Bytes del archivo como texto UTF-8, o null si no lo son. «fatal» hace que
 * falle en vez de cambiar en silencio los bytes no válidos por «�».
 */
function decodificarUtf8(bytes) {
    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch {
        return null;
    }
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
