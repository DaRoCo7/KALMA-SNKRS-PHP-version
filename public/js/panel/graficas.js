/*
 * Gráficas del panel en SVG propio, sin librerías: el despliegue del aula
 * puede no tener internet y así no se descarga nada de fuera.
 *
 * Tres formas, una por trabajo:
 *   - barras():   ranking (ventas por modelo, stock más bajo). Horizontales
 *                 porque los nombres de las zapatillas son largos.
 *   - columnas(): una cifra por mes, el tiempo de izquierda a derecha.
 *   - linea():    la evolución de una cifra por mes cuando ya hay columnas de
 *                 otra (dos medidas = dos gráficas, nunca dos ejes).
 *
 * Reglas que siguen todas:
 *   - Una sola serie, en negro y grises (sin colores de acento). Si el título
 *     habla de un elemento (el mejor mes, el modelo que más vende) ese va en
 *     negro y el resto en gris; si no, todas en negro.
 *   - El título lo escribe quien llama y dice la conclusión, no el tema.
 *   - Marcas finas (barras de 12-24 px con la punta redondeada y la base
 *     recta), rejilla y ejes en líneas de 1 px casi invisibles y cifras
 *     tabulares en los ejes.
 *   - Cada marca se puede enfocar con el teclado y enseña su valor al pasar o
 *     al enfocar. Ese valor también está en la tabla «Ver los datos», que es
 *     la versión accesible completa: el tooltip ayuda, nunca es la única vía.
 *   - Se redibujan al cambiar el ancho de su caja (también a 390 px) y entran
 *     creciendo desde la base una sola vez; con prefers-reduced-motion, no
 *     se mueven (lo resuelve panel-informes.css).
 *   - Todo lo que viene de la API se pinta con textContent.
 */

import { crear } from '../api.js';

const SVG = 'http://www.w3.org/2000/svg';

/** Grosor de las marcas: nunca llenan su hueco, el aire que queda separa */
const GROSOR_BARRA = 12;
const GROSOR_MAXIMO = 24;
const RADIO = 4;

const compacto = new Intl.NumberFormat('es-ES', { notation: 'compact', maximumFractionDigits: 1 });
const entero = new Intl.NumberFormat('es-ES');

let contadorIds = 0;
let lienzoMedidas = null;

function svg(etiqueta, atributos = {}, texto = null) {
    const elemento = document.createElementNS(SVG, etiqueta);
    for (const [nombre, valor] of Object.entries(atributos)) {
        elemento.setAttribute(nombre, String(valor));
    }
    if (texto !== null) {
        elemento.textContent = texto;
    }
    return elemento;
}

/** Ancho en píxeles de un texto con la fuente del panel (para recortar sin pisar nada) */
function medir(texto, tamano = 13, peso = 400) {
    lienzoMedidas ??= document.createElement('canvas').getContext('2d');
    lienzoMedidas.font = `${peso} ${tamano}px ${getComputedStyle(document.body).fontFamily}`;
    return lienzoMedidas.measureText(texto).width;
}

/** Recorta con «…» lo que no cabe; el texto entero sigue en el tooltip y en la tabla */
function recortar(texto, ancho, tamano = 13, peso = 400) {
    if (medir(texto, tamano, peso) <= ancho) {
        return texto;
    }
    let corte = texto.length;
    while (corte > 1 && medir(texto.slice(0, corte) + '…', tamano, peso) > ancho) {
        corte--;
    }
    return texto.slice(0, corte).trimEnd() + '…';
}

/** Paso «redondo» del eje (1, 2, 2,5 o 5 por potencia de 10) para unas 4 divisiones */
function pasoEje(maximo, divisiones = 4) {
    if (maximo <= 0) {
        return 1;
    }
    const bruto = maximo / divisiones;
    const magnitud = 10 ** Math.floor(Math.log10(bruto));
    const relativo = bruto / magnitud;
    const factor = relativo <= 1 ? 1 : relativo <= 2 ? 2 : relativo <= 2.5 ? 2.5 : relativo <= 5 ? 5 : 10;
    return factor * magnitud;
}

/** Cifras del eje: completas por debajo de 10.000 y compactas a partir de ahí (12 mil) */
export function cifraEje(valor) {
    return Math.abs(valor) < 10000 ? entero.format(valor) : compacto.format(valor);
}

/** Barra con la punta redondeada y la base recta, hacia la derecha */
function caminoHorizontal(x, y, largo, grosor) {
    const r = Math.min(RADIO, largo / 2, grosor / 2);
    if (largo <= 0) {
        return '';
    }
    return `M${x},${y}H${x + largo - r}Q${x + largo},${y} ${x + largo},${y + r}`
        + `V${y + grosor - r}Q${x + largo},${y + grosor} ${x + largo - r},${y + grosor}H${x}Z`;
}

/** Columna con la punta redondeada arriba y la base recta abajo */
function caminoVertical(x, base, alto, grosor) {
    const r = Math.min(RADIO, alto / 2, grosor / 2);
    if (alto <= 0) {
        return '';
    }
    const arriba = base - alto;
    return `M${x},${base}V${arriba + r}Q${x},${arriba} ${x + r},${arriba}`
        + `H${x + grosor - r}Q${x + grosor},${arriba} ${x + grosor},${arriba + r}V${base}Z`;
}

/**
 * Esqueleto común: figura con título (la conclusión), subtítulo, el lienzo
 * con su tooltip y la tabla equivalente plegada debajo.
 */
function figura({ titulo, subtitulo, filas, filasTabla, columnasTabla, vacio }) {
    const id = `grafica-${++contadorIds}`;
    const caja = crear('figure', 'grafica');
    const pie = crear('figcaption', 'grafica__cabeza');
    const h = crear('h3', 'grafica__titulo', titulo);
    h.id = `${id}-titulo`;
    pie.append(h);
    if (subtitulo) {
        pie.append(crear('p', 'grafica__sub', subtitulo));
    }
    caja.append(pie);

    if (filas.length === 0) {
        caja.append(crear('p', 'grafica__vacio', vacio ?? 'No hay datos para este periodo.'));
        return { caja, lienzo: null, tooltip: null, id };
    }

    const lienzo = crear('div', 'grafica__lienzo');
    const tooltip = crear('div', 'grafica__tooltip');
    tooltip.setAttribute('aria-hidden', 'true');   // lo mismo lo lee el aria-label de la marca
    lienzo.append(tooltip);
    caja.append(lienzo);

    if (columnasTabla) {
        // La tabla puede traer más filas que la gráfica (la gráfica enseña las 10 primeras)
        caja.append(tablaDatos(filasTabla ?? filas, columnasTabla));
    }
    return { caja, lienzo, tooltip, id };
}

/** La tabla con los mismos datos: sin color ni ratón, todo se puede leer */
function tablaDatos(filas, columnas) {
    const pliegue = crear('details', 'grafica__datos');
    pliegue.append(crear('summary', 'grafica__resumen-tabla', 'Ver los datos en una tabla'));
    const tabla = crear('table', 'tabla');
    const cabeza = crear('thead');
    const filaCabeza = crear('tr');
    for (const columna of columnas) {
        const th = crear('th', columna.num ? 'num' : '', columna.titulo);
        th.scope = 'col';
        filaCabeza.append(th);
    }
    cabeza.append(filaCabeza);
    const cuerpo = crear('tbody');
    for (const fila of filas) {
        const tr = crear('tr');
        for (const columna of columnas) {
            tr.append(crear('td', columna.num ? 'num' : '', columna.valor(fila)));
        }
        cuerpo.append(tr);
    }
    tabla.append(cabeza, cuerpo);
    const envoltorio = crear('div', 'tabla-desplazable');
    envoltorio.append(tabla);
    pliegue.append(envoltorio);
    return pliegue;
}

/**
 * Tooltip de una marca: el valor primero (es lo que se busca), la etiqueta
 * después. Se coloca encima de la marca sin salirse del lienzo.
 */
function conectarTooltip(lienzo, tooltip, marca, { valor, etiqueta, detalle }, ancla) {
    const mostrar = () => {
        tooltip.replaceChildren(crear('strong', 'grafica__tooltip-valor', valor), crear('span', '', etiqueta));
        if (detalle) {
            tooltip.append(crear('span', 'grafica__tooltip-detalle', detalle));
        }
        tooltip.classList.add('grafica__tooltip--visible');
        const ancho = lienzo.clientWidth;
        const caja = tooltip.getBoundingClientRect();
        const { x, y } = ancla();
        const izquierda = Math.min(Math.max(0, x - caja.width / 2), Math.max(0, ancho - caja.width));
        const arriba = y - caja.height - 8 < 0 ? y + 20 : y - caja.height - 8;
        tooltip.style.transform = `translate(${Math.round(izquierda)}px, ${Math.round(arriba)}px)`;
        marca.classList.add('grafica__marca--activa');
    };
    const ocultar = () => {
        tooltip.classList.remove('grafica__tooltip--visible');
        marca.classList.remove('grafica__marca--activa');
    };
    marca.addEventListener('pointerenter', mostrar);
    marca.addEventListener('pointerleave', ocultar);
    marca.addEventListener('focus', mostrar);
    marca.addEventListener('blur', ocultar);
}

/**
 * Dibuja con el ancho de la caja y vuelve a dibujar si cambia. La animación
 * de entrada solo va en el primer dibujo: al girar el móvil no se repite.
 */
function dibujarAjustado(lienzo, dibujar) {
    let anchoAnterior = 0;
    let primera = true;
    const pintar = (ancho) => {
        if (ancho < 1 || Math.abs(ancho - anchoAnterior) < 1) {
            return;
        }
        anchoAnterior = ancho;
        lienzo.querySelector('svg')?.remove();
        const dibujo = dibujar(Math.floor(ancho));
        if (primera) {
            dibujo.classList.add('grafica__svg--entrada');
            primera = false;
        }
        lienzo.prepend(dibujo);
    };
    const observador = new ResizeObserver((entradas) => {
        if (!lienzo.isConnected) {
            observador.disconnect();
            return;
        }
        pintar(entradas[0].contentRect.width);
    });
    observador.observe(lienzo);
}

function svgBase(ancho, alto, idTitulo) {
    return svg('svg', {
        class: 'grafica__svg',
        width: ancho,
        height: alto,
        viewBox: `0 0 ${ancho} ${alto}`,
        role: 'group',
        'aria-labelledby': idTitulo,
    });
}

/** Marca enfocable con su nombre accesible: «Air Jordan 1: 1.239,96 €» */
function grupoMarca(etiqueta, valor, retraso) {
    const g = svg('g', { class: 'grafica__marca', tabindex: 0, role: 'img', 'aria-label': `${etiqueta}: ${valor}` });
    g.style.setProperty('--retraso', `${Math.min(retraso, 12) * 30}ms`);
    return g;
}

// =============================================================================
// Barras horizontales
// =============================================================================

/**
 * @param {object} o
 * @param {string} o.titulo           la conclusión («Air Jordan 1 lidera las ventas»)
 * @param {string} [o.subtitulo]      qué se mide y en qué periodo
 * @param {Array}  o.filas
 * @param {(f) => number} o.valor
 * @param {(f) => string} o.etiqueta  nombre de la barra
 * @param {(f) => string} [o.sub]     segunda línea (el colorway)
 * @param {(n) => string} o.formato   valor en la punta y en el tooltip
 * @param {(f) => string} [o.detalle] línea extra del tooltip
 * @param {(f, i) => boolean} [o.destacar] la que va en negro (el resto en gris)
 * @param {Array} [o.columnasTabla]   [{ titulo, valor(f), num }]
 */
export function barras(o) {
    const { caja, lienzo, tooltip, id } = figura(o);
    if (!lienzo) {
        return caja;
    }
    const maximo = Math.max(...o.filas.map(o.valor), 0);

    dibujarAjustado(lienzo, (ancho) => {
        // Estrecho (móvil): el nombre encima de la barra, a todo el ancho.
        // Ancho: el nombre a la izquierda en su columna.
        const apilado = ancho < 560;
        const conSub = Boolean(o.sub);
        const anchoEtiqueta = apilado ? 0 : Math.min(240, Math.round(ancho * 0.36));
        const valores = o.filas.map((f) => o.formato(o.valor(f)));
        const anchoValor = Math.ceil(Math.max(...valores.map((v) => medir(v, 12)))) + 10;
        const x0 = apilado ? 0 : anchoEtiqueta + 12;
        const largoMaximo = Math.max(20, ancho - x0 - anchoValor);
        const altoTexto = apilado ? (conSub ? 34 : 18) : 0;
        const fila = apilado ? altoTexto + GROSOR_BARRA + 14 : (conSub ? 44 : 32);
        const alto = o.filas.length * fila + 4;
        const dibujo = svgBase(ancho, alto, `${id}-titulo`);

        // La base de todas las barras: una sola línea, recesiva. Apiladas no hace
        // falta (todas empiezan en el borde) y pasaría por encima de los nombres
        if (!apilado) {
            dibujo.append(svg('line', { class: 'grafica__base', x1: x0 + 0.5, x2: x0 + 0.5, y1: 0, y2: alto }));
        }

        o.filas.forEach((f, i) => {
            const y = i * fila + 2;
            const etiqueta = o.etiqueta(f);
            const sub = o.sub?.(f) ?? '';
            const g = grupoMarca(sub ? `${etiqueta} ${sub}` : etiqueta, valores[i], i);

            // Fondo del tamaño de toda la fila: el objetivo del ratón es la fila, no los 12 px de la barra
            g.append(svg('rect', { class: 'grafica__zona', x: 0, y, width: ancho, height: fila, rx: 4 }));

            const yBarra = apilado ? y + altoTexto : y + (fila - GROSOR_BARRA) / 2;
            const largo = maximo > 0 ? Math.max(o.valor(f) > 0 ? 2 : 0, (o.valor(f) / maximo) * largoMaximo) : 0;
            const destacada = o.destacar ? o.destacar(f, i) : true;
            g.append(svg('path', {
                class: 'grafica__barra' + (destacada ? '' : ' grafica__barra--contexto'),
                d: caminoHorizontal(x0, yBarra, largo, GROSOR_BARRA),
            }));
            g.append(svg('text', {
                class: 'grafica__valor', x: x0 + largo + 6, y: yBarra + GROSOR_BARRA / 2, 'dominant-baseline': 'central',
            }, valores[i]));

            const anchoTexto = apilado ? ancho : anchoEtiqueta;
            if (apilado) {
                g.append(svg('text', { class: 'grafica__etiqueta', x: 0, y: y + 13 }, recortar(etiqueta, anchoTexto)));
                if (sub) {
                    g.append(svg('text', { class: 'grafica__etiqueta-sub', x: 0, y: y + 29 }, recortar(sub, anchoTexto, 12)));
                }
            } else {
                const centro = y + fila / 2;
                g.append(svg('text', {
                    class: 'grafica__etiqueta', x: 0, y: sub ? centro - 3 : centro + 4,
                }, recortar(etiqueta, anchoTexto)));
                if (sub) {
                    g.append(svg('text', { class: 'grafica__etiqueta-sub', x: 0, y: centro + 13 }, recortar(sub, anchoTexto, 12)));
                }
            }

            conectarTooltip(lienzo, tooltip, g, {
                valor: valores[i],
                etiqueta: sub ? `${etiqueta} · ${sub}` : etiqueta,
                detalle: o.detalle?.(f),
            }, () => ({ x: x0 + largo, y: yBarra }));
            dibujo.append(g);
        });
        return dibujo;
    });

    return caja;
}

// =============================================================================
// Columnas y línea por mes (comparten el eje)
// =============================================================================

const ALTO_TRAZADO = 200;
const BANDA_EJE_X = 28;
const MARGEN_ARRIBA = 22;   // sitio para la cifra encima de la columna más alta

/** Eje Y con su rejilla. Devuelve la escala y dónde empieza el trazado */
function ejeY(dibujo, ancho, maximo, formatoEje) {
    const paso = pasoEje(maximo);
    const tope = Math.max(paso, Math.ceil(maximo / paso) * paso);
    const marcas = [];
    for (let v = 0; v <= tope + paso / 2; v += paso) {
        marcas.push(v);
    }
    const anchoEje = Math.ceil(Math.max(...marcas.map((v) => medir(formatoEje(v), 12)))) + 8;
    const base = MARGEN_ARRIBA + ALTO_TRAZADO;
    const escala = (v) => base - (v / tope) * ALTO_TRAZADO;

    const rejilla = svg('g', { 'aria-hidden': 'true' });
    for (const v of marcas) {
        const y = Math.round(escala(v)) + 0.5;
        rejilla.append(svg('line', { class: v === 0 ? 'grafica__base' : 'grafica__regla', x1: anchoEje, x2: ancho, y1: y, y2: y }));
        rejilla.append(svg('text', {
            class: 'grafica__eje', x: anchoEje - 8, y, 'text-anchor': 'end', 'dominant-baseline': 'central',
        }, formatoEje(v)));
    }
    dibujo.append(rejilla);
    return { escala, base, x0: anchoEje + 4 };
}

/** Rótulos del eje X; si no caben todos, uno de cada N (siempre el primero y el último) */
function ejeX(dibujo, filas, etiquetaEje, centroDe, banda, base) {
    const textos = filas.map((f, i) => etiquetaEje(f, i));
    const mayor = Math.max(...textos.map((t) => medir(t, 12)));
    const cada = Math.max(1, Math.ceil((mayor + 8) / banda));
    const grupo = svg('g', { 'aria-hidden': 'true' });
    const ultimo = textos.length - 1;
    textos.forEach((texto, i) => {
        // Se cuenta desde el final: el mes más reciente, el que más se mira, siempre lleva rótulo
        if ((ultimo - i) % cada === 0) {
            grupo.append(svg('text', { class: 'grafica__eje', x: centroDe(i), y: base + 18, 'text-anchor': 'middle' }, texto));
        }
    });
    dibujo.append(grupo);
}

/**
 * @param {object} o  como barras(), más:
 * @param {(f, i) => string} o.etiquetaEje  rótulo corto del eje X («oct»)
 * @param {(n) => string} [o.formatoEje]
 */
export function columnas(o) {
    const { caja, lienzo, tooltip, id } = figura(o);
    if (!lienzo) {
        return caja;
    }
    const datos = o.filas.map(o.valor);
    const maximo = Math.max(...datos, 0);
    const iMaximo = datos.indexOf(maximo);
    const formatoEje = o.formatoEje ?? cifraEje;

    dibujarAjustado(lienzo, (ancho) => {
        const dibujo = svgBase(ancho, MARGEN_ARRIBA + ALTO_TRAZADO + BANDA_EJE_X, `${id}-titulo`);
        const { escala, base, x0 } = ejeY(dibujo, ancho, maximo, formatoEje);
        const banda = (ancho - x0) / o.filas.length;
        const grosor = Math.max(4, Math.min(GROSOR_MAXIMO, banda * 0.6));
        const centroDe = (i) => x0 + banda * i + banda / 2;

        o.filas.forEach((f, i) => {
            const valor = o.formato(datos[i]);
            const g = grupoMarca(o.etiqueta(f), valor, i);
            g.append(svg('rect', { class: 'grafica__zona', x: x0 + banda * i, y: MARGEN_ARRIBA - 18, width: banda, height: ALTO_TRAZADO + 18, rx: 4 }));
            const alto = base - escala(datos[i]);
            const destacada = o.destacar ? o.destacar(f, i) : true;
            g.append(svg('path', {
                class: 'grafica__columna' + (destacada ? '' : ' grafica__barra--contexto'),
                d: caminoVertical(centroDe(i) - grosor / 2, base, Math.max(datos[i] > 0 ? 2 : 0, alto), grosor),
            }));
            // Cifra solo en la más alta y en la última: el resto, en el eje, el tooltip y la tabla
            if ((i === iMaximo || i === o.filas.length - 1) && medir(valor, 12, 700) < banda * 2.2) {
                g.append(svg('text', {
                    class: 'grafica__valor grafica__valor--cima', x: centroDe(i), y: base - alto - 6, 'text-anchor': 'middle',
                }, valor));
            }
            conectarTooltip(lienzo, tooltip, g, { valor, etiqueta: o.etiqueta(f), detalle: o.detalle?.(f) },
                () => ({ x: centroDe(i), y: base - alto }));
            dibujo.append(g);
        });

        ejeX(dibujo, o.filas, o.etiquetaEje, centroDe, banda, base);
        return dibujo;
    });
    return caja;
}

/**
 * Línea de 2 px con un punto al final y la cifra de ese último punto. Al pasar
 * el ratón por cualquier sitio del trazado, una línea vertical marca el mes
 * más cercano y el tooltip da su cifra; con el teclado se va de mes en mes con
 * las flechas.
 */
export function linea(o) {
    const { caja, lienzo, tooltip, id } = figura(o);
    if (!lienzo) {
        return caja;
    }
    const datos = o.filas.map(o.valor);
    const maximo = Math.max(...datos, 0);
    const formatoEje = o.formatoEje ?? cifraEje;

    dibujarAjustado(lienzo, (ancho) => {
        const dibujo = svgBase(ancho, MARGEN_ARRIBA + ALTO_TRAZADO + BANDA_EJE_X, `${id}-titulo`);
        const { escala, base, x0 } = ejeY(dibujo, ancho, maximo, formatoEje);
        const ultimo = o.formato(datos[datos.length - 1]);
        // Hueco a la derecha para la cifra del último punto
        const derecha = Math.ceil(medir(ultimo, 12, 700)) + 14;
        const banda = (ancho - x0 - derecha) / Math.max(1, o.filas.length - 1);
        const xDe = (i) => (o.filas.length === 1 ? x0 + (ancho - x0 - derecha) / 2 : x0 + banda * i);

        const puntos = datos.map((v, i) => `${xDe(i).toFixed(1)},${escala(v).toFixed(1)}`);
        dibujo.append(svg('path', { class: 'grafica__linea', d: 'M' + puntos.join('L'), pathLength: 1 }));

        const n = datos.length - 1;
        dibujo.append(svg('circle', { class: 'grafica__punto', cx: xDe(n), cy: escala(datos[n]), r: 4 }));
        dibujo.append(svg('text', {
            class: 'grafica__valor', x: xDe(n) + 8, y: escala(datos[n]), 'dominant-baseline': 'central',
        }, ultimo));

        // Retícula que sigue al ratón y el punto del mes elegido
        const reticula = svg('line', { class: 'grafica__reticula', y1: MARGEN_ARRIBA, y2: base });
        const elegido = svg('circle', { class: 'grafica__punto grafica__punto--elegido', r: 4 });
        const zona = svg('g', {
            class: 'grafica__marca grafica__marca--linea', tabindex: 0, role: 'img',
            'aria-label': o.filas.map((f, i) => `${o.etiqueta(f)}: ${o.formato(datos[i])}`).join('; '),
        });
        zona.append(svg('rect', { class: 'grafica__zona grafica__zona--invisible', x: x0 - 8, y: MARGEN_ARRIBA, width: ancho - x0 + 8, height: ALTO_TRAZADO }));
        zona.append(reticula, elegido);
        dibujo.append(zona);

        let actual = n;
        const ir = (i) => {
            actual = Math.max(0, Math.min(n, i));
            const x = xDe(actual);
            const y = escala(datos[actual]);
            reticula.setAttribute('x1', x);
            reticula.setAttribute('x2', x);
            elegido.setAttribute('cx', x);
            elegido.setAttribute('cy', y);
            tooltip.replaceChildren(
                crear('strong', 'grafica__tooltip-valor', o.formato(datos[actual])),
                crear('span', '', o.etiqueta(o.filas[actual])),
            );
            const detalle = o.detalle?.(o.filas[actual]);
            if (detalle) {
                tooltip.append(crear('span', 'grafica__tooltip-detalle', detalle));
            }
            tooltip.classList.add('grafica__tooltip--visible');
            const cajaT = tooltip.getBoundingClientRect();
            const izquierda = Math.min(Math.max(0, x - cajaT.width / 2), Math.max(0, lienzo.clientWidth - cajaT.width));
            const arriba = y - cajaT.height - 10 < 0 ? y + 14 : y - cajaT.height - 10;
            tooltip.style.transform = `translate(${Math.round(izquierda)}px, ${Math.round(arriba)}px)`;
            zona.classList.add('grafica__marca--activa');
        };
        const salir = () => {
            tooltip.classList.remove('grafica__tooltip--visible');
            zona.classList.remove('grafica__marca--activa');
        };
        zona.addEventListener('pointermove', (evento) => {
            const x = evento.clientX - dibujo.getBoundingClientRect().left;
            ir(o.filas.length === 1 ? 0 : Math.round((x - x0) / banda));
        });
        zona.addEventListener('pointerleave', salir);
        zona.addEventListener('focus', () => ir(actual));
        zona.addEventListener('blur', salir);
        zona.addEventListener('keydown', (evento) => {
            const pasos = { ArrowLeft: -1, ArrowRight: 1, Home: -Infinity, End: Infinity };
            if (evento.key in pasos) {
                evento.preventDefault();
                ir(Number.isFinite(pasos[evento.key]) ? actual + pasos[evento.key] : (pasos[evento.key] < 0 ? 0 : n));
            }
        });

        ejeX(dibujo, o.filas, o.etiquetaEje, xDe, Math.max(banda, 1), base);
        return dibujo;
    });
    return caja;
}

// =============================================================================
// Ayudas de formato para quien llama
// =============================================================================

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** «2026-10» -> «octubre de 2026» */
export function nombreMes(aaaamm) {
    const [anio, mes] = aaaamm.split('-').map(Number);
    return `${MESES[mes - 1]} de ${anio}`;
}

/** «2026-10» -> «oct»; en enero (o el primero de la serie) se añade el año: «ene 27» */
export function mesCorto(aaaamm, i) {
    const [anio, mes] = aaaamm.split('-').map(Number);
    const corto = MESES[mes - 1].slice(0, 3);
    return i === 0 || mes === 1 ? `${corto} ${String(anio).slice(2)}` : corto;
}
