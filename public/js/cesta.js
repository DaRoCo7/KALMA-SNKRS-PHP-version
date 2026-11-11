/*
 * Cesta de la compra. Vive en el navegador (localStorage) hasta que se hace el
 * pedido, así sobrevive a recargar la página y no exige cuenta para ir eligiendo.
 * La cuenta se pide al final, dentro del mismo panel, sin salir de la página.
 *
 * Cada línea guarda lo necesario para pintarla sin volver a pedir la ficha
 * (nombre, foto, precio, stock visto). Esos precios son solo una estimación:
 * al pedido se envían únicamente la talla, la cantidad y las opciones, y el
 * servidor calcula el importe (contrato, punto 9.1).
 */
import { api, ErrorApi, fijarTokenCsrf, precio, talla, crear, prepararDialogo, cerrarDialogo, movimientoReducido } from './api.js';

const CLAVE = 'kalma.cesta';
export const MAX_LINEAS = 20;
export const MAX_PARES = 20;

// --- Almacenamiento ---------------------------------------------------------
// localStorage puede no existir o lanzar (modo privado, cookies bloqueadas):
// en ese caso la cesta funciona igual, en memoria, hasta cerrar la pestaña.

let lineas = leer();

function leer() {
    try {
        const guardadas = JSON.parse(window.localStorage.getItem(CLAVE) ?? '[]');
        if (!Array.isArray(guardadas)) {
            return [];
        }
        // Una cesta de una versión anterior (o tocada a mano) puede no traer la
        // personalización como lista: se normaliza a [] para que no rompa la página
        return guardadas
            .filter((l) => Number.isInteger(l?.variante_id) && l.cantidad > 0)
            .map((l) => ({
                ...l,
                personalizacion: Array.isArray(l.personalizacion) ? l.personalizacion.filter((o) => o && typeof o === 'object') : [],
            }));
    } catch {
        return [];
    }
}

function guardar() {
    try {
        window.localStorage.setItem(CLAVE, JSON.stringify(lineas));
    } catch {
        // Sin almacenamiento: la cesta sigue en memoria
    }
    pintarContador();
    if (dialogo?.open) {
        pintarLineas();
    }
}

// Con la tienda abierta en dos pestañas, la cesta es la misma en las dos
window.addEventListener('storage', (evento) => {
    if (evento.key === CLAVE) {
        lineas = leer();
        pintarContador();
        if (dialogo?.open) {
            pintarLineas();
        }
    }
});

export function pares() {
    return lineas.reduce((suma, l) => suma + l.cantidad, 0);
}

/** Pares de una talla que ya están en la cesta (para no ofrecer más que el stock) */
export function enCesta(varianteId) {
    return lineas.find((l) => l.variante_id === varianteId)?.cantidad ?? 0;
}

function importeLinea(linea) {
    const recargo = linea.personalizacion.reduce((suma, o) => suma + o.recargo, 0);
    return (linea.precio + recargo) * linea.cantidad;
}

/**
 * Añade una línea. La API admite una sola línea por talla, así que si la
 * talla ya está en la cesta se juntan los pares en la misma línea y se queda
 * la personalización recién elegida.
 *
 * @returns {{ok: boolean, mensaje: string}}
 */
export function anadirALaCesta(nueva) {
    const limite = Math.min(MAX_PARES, nueva.stock);
    const existente = lineas.find((l) => l.variante_id === nueva.variante_id);

    if (!existente && lineas.length >= MAX_LINEAS) {
        return { ok: false, mensaje: `La cesta admite ${MAX_LINEAS} tallas distintas como máximo.` };
    }

    let mensaje = 'Añadido a la cesta.';
    if (existente) {
        const total = existente.cantidad + nueva.cantidad;
        Object.assign(existente, nueva, { cantidad: Math.min(total, limite) });
        mensaje = total > limite
            ? `Ya tenías esta talla: se queda en ${existente.cantidad} ${existente.cantidad === 1 ? 'par' : 'pares'}, el máximo disponible.`
            : 'Ya tenías esta talla: se han juntado los pares en la misma línea.';
    } else {
        lineas.push({ ...nueva, cantidad: Math.min(nueva.cantidad, limite) });
    }
    guardar();
    avisarContador();
    return { ok: true, mensaje };
}

// --- Cabecera: el botón con el número de pares --------------------------------

const botones = document.querySelectorAll('[data-cesta-abrir]');

function pintarContador() {
    const n = pares();
    botones.forEach((boton) => {
        boton.querySelector('[data-cesta-numero]').textContent = String(n);
        boton.classList.toggle('boton-cesta--llena', n > 0);
        boton.setAttribute('aria-label', n === 0 ? 'Cesta vacía' : `Cesta: ${n} ${n === 1 ? 'par' : 'pares'}`);
    });
}

/** Un pequeño salto del número: confirma que el par ha entrado sin abrir nada */
function avisarContador() {
    if (movimientoReducido()) {
        return;
    }
    botones.forEach((boton) => {
        boton.querySelector('[data-cesta-numero]').animate(
            [{ transform: 'scale(1)' }, { transform: 'scale(1.35)' }, { transform: 'scale(1)' }],
            { duration: 320, easing: 'cubic-bezier(0.23, 1, 0.32, 1)' },
        );
    });
}

// --- Panel lateral -------------------------------------------------------------

const dialogo = document.getElementById('cesta');
const titulo = dialogo?.querySelector('#cesta-titulo');
const vistas = dialogo ? Object.fromEntries([...dialogo.querySelectorAll('[data-vista]')].map((v) => [v.dataset.vista, v])) : {};
const lista = dialogo?.querySelector('[data-cesta-lineas]');
const formPedido = dialogo?.querySelector('[data-cesta-pedido]');
const formEntrar = dialogo?.querySelector('[data-cesta-entrar]');

const TITULOS = { lineas: 'Cesta', entrar: 'Entra para pedir', hecho: 'Pedido hecho' };

// Sesión según la API; se pregunta al abrir la cesta por primera vez
let sesion = null;
// Errores del último intento, por variante: se pintan junto a su línea
let erroresLinea = new Map();

export function abrirCesta() {
    if (!dialogo) {
        return;
    }
    mostrarVista('lineas');
    pintarLineas();
    if (!dialogo.open) {
        dialogo.showModal();
    }
    prepararEnvio();
}

function mostrarVista(nombre, enfocar = false) {
    Object.entries(vistas).forEach(([clave, vista]) => {
        vista.hidden = clave !== nombre;
    });
    titulo.textContent = TITULOS[nombre];
    if (enfocar) {
        titulo.focus();
    }
}

function pintarLineas() {
    const vacia = lineas.length === 0;
    dialogo.querySelector('[data-cesta-vacia]').hidden = !vacia;
    formPedido.hidden = vacia;

    // El repintado sustituye los botones: se recuerda cuál tenía el foco para devolvérselo
    const foco = document.activeElement?.closest?.('[data-accion]');
    const recordar = foco && lista.contains(foco) ? `[data-accion="${foco.dataset.accion}"][data-variante="${foco.dataset.variante}"]` : null;

    lista.replaceChildren(...lineas.map(pintarLinea));
    dialogo.querySelector('[data-cesta-total]').textContent = precio(lineas.reduce((s, l) => s + importeLinea(l), 0));

    if (recordar) {
        (lista.querySelector(recordar) ?? titulo).focus();
    }
}

function pintarLinea(linea) {
    const item = crear('li', 'linea');

    const foto = crear('div', 'linea__foto');
    const img = crear('img');
    img.src = linea.imagen;
    img.alt = '';
    img.width = 160;
    img.height = 160;
    img.loading = 'lazy';
    img.addEventListener('error', () => img.remove(), { once: true });
    foto.append(img);

    const datos = crear('div', 'linea__datos');
    datos.append(
        crear('p', 'linea__nombre', linea.nombre),
        crear('p', 'linea__detalle', `${linea.colorway} · Talla ${talla(linea.talla)}`),
    );
    if (linea.personalizacion.length > 0) {
        const extras = crear('ul', 'linea__extras');
        linea.personalizacion.forEach((o) => {
            const texto = o.texto ? `${o.nombre}: «${o.texto}»` : o.nombre;
            extras.append(crear('li', '', `${texto} (+${precio(o.recargo)} por par)`));
        });
        datos.append(extras);
    }

    const controles = crear('div', 'linea__controles');
    const maximo = Math.min(MAX_PARES, linea.stock);
    const menos = botonLinea('menos', linea, '−', `Quitar un par de ${linea.nombre}`);
    const mas = botonLinea('mas', linea, '+', `Añadir un par de ${linea.nombre}`);
    menos.disabled = linea.cantidad <= 1;
    mas.disabled = linea.cantidad >= maximo;
    const cantidad = crear('span', 'linea__cantidad', String(linea.cantidad));
    cantidad.setAttribute('aria-label', `${linea.cantidad} ${linea.cantidad === 1 ? 'par' : 'pares'}`);
    const quitar = botonLinea('quitar', linea, 'Quitar', null);
    quitar.className = 'linea__quitar';
    controles.append(crear('div', 'paso', null), quitar);
    controles.firstChild.append(menos, cantidad, mas);
    datos.append(controles);

    const error = erroresLinea.get(linea.variante_id);
    if (error) {
        datos.append(crear('p', 'campo__error linea__error', error));
    }

    item.append(foto, datos, crear('p', 'linea__importe', precio(importeLinea(linea))));
    return item;
}

function botonLinea(accion, linea, texto, etiqueta) {
    const boton = crear('button', 'paso__boton', texto);
    boton.type = 'button';
    boton.dataset.accion = accion;
    boton.dataset.variante = String(linea.variante_id);
    if (etiqueta) {
        boton.setAttribute('aria-label', etiqueta);
    }
    return boton;
}

function alPulsarLinea(evento) {
    const boton = evento.target.closest('[data-accion]');
    if (!boton) {
        return;
    }
    const id = Number(boton.dataset.variante);
    const linea = lineas.find((l) => l.variante_id === id);
    if (!linea) {
        return;
    }
    erroresLinea.delete(id);
    if (boton.dataset.accion === 'quitar') {
        lineas = lineas.filter((l) => l !== linea);
        // La línea desaparece con su botón: el foco pasa al título para no perderse
        titulo.focus();
    } else {
        const cambio = boton.dataset.accion === 'mas' ? 1 : -1;
        linea.cantidad = Math.max(1, Math.min(Math.min(MAX_PARES, linea.stock), linea.cantidad + cambio));
    }
    guardar();
}

// --- Sesión y dirección ------------------------------------------------------

async function consultarSesion() {
    const { datos } = await api('/api/sesion');
    sesion = datos;
    fijarTokenCsrf(datos.csrf_token);
    return datos;
}

/** Al abrir la cesta, si hay un cliente con sesión, se rellena su dirección */
async function prepararEnvio() {
    try {
        const datos = sesion ?? await consultarSesion();
        if (datos.autenticado && datos.usuario?.rol === 'cliente') {
            await rellenarDireccion();
        }
    } catch {
        // Sin conexión: el error se verá al pedir
    }
}

async function rellenarDireccion() {
    const campo = formPedido.elements.direccion_envio;
    if (campo.value.trim() !== '') {
        return;   // lo que haya escrito la persona manda sobre el perfil
    }
    try {
        const { datos: perfil } = await api('/api/perfil');
        // Mismo formato que usa el servidor cuando no se envía dirección
        const localidad = [perfil.codigo_postal, perfil.ciudad].filter(Boolean).join(' ');
        let direccion = [perfil.direccion, localidad].filter(Boolean).join(', ');
        if (direccion && perfil.provincia) {
            direccion += ` (${perfil.provincia})`;
        }
        if (perfil.direccion && campo.value.trim() === '') {
            campo.value = direccion;
        }
    } catch {
        // Sin perfil o sin conexión: la persona la escribe a mano
    }
}

// --- Hacer el pedido ---------------------------------------------------------

function mensaje(vista, texto) {
    vistas[vista].querySelector('[data-cesta-mensaje]').textContent = texto;
}

function limpiarErrores(form) {
    form.querySelectorAll('[data-error]').forEach((p) => { p.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach((c) => c.removeAttribute('aria-invalid'));
}

function errorCampo(form, campo, texto) {
    const destino = form.querySelector(`[data-error="${campo}"]`);
    if (destino) {
        destino.textContent = texto;
        form.elements[campo]?.setAttribute('aria-invalid', 'true');
    }
}

function cuerpoPedido() {
    const direccion = formPedido.elements.direccion_envio.value.trim();
    const notas = formPedido.elements.notas.value.trim();
    return {
        // Sin dirección escrita, el servidor usa la del perfil
        ...(direccion !== '' ? { direccion_envio: direccion } : {}),
        ...(notas !== '' ? { notas } : {}),
        lineas: lineas.map((l) => ({
            variante_id: l.variante_id,
            cantidad: l.cantidad,
            personalizacion: l.personalizacion.map((o) => (o.texto ? { opcion_id: o.opcion_id, texto: o.texto } : { opcion_id: o.opcion_id })),
        })),
    };
}

async function hacerPedido(evento) {
    evento.preventDefault();
    if (lineas.length === 0) {
        return;
    }
    const boton = formPedido.querySelector('[type="submit"]');
    limpiarErrores(formPedido);
    mensaje('lineas', '');
    erroresLinea = new Map();
    boton.disabled = true;
    boton.textContent = 'Enviando el pedido…';

    try {
        const datos = await consultarSesion();
        if (!datos.autenticado) {
            irAEntrar();
            return;
        }
        if (datos.usuario?.rol !== 'cliente') {
            mensaje('lineas', 'Las cuentas de la tienda no pueden hacer pedidos. Entra con una cuenta de cliente.');
            return;
        }
        await enviarPedido();
    } catch (error) {
        mensaje('lineas', error instanceof ErrorApi ? error.message : 'Algo ha fallado. Vuelve a intentarlo.');
    } finally {
        boton.disabled = false;
        boton.textContent = 'Hacer pedido';
    }
}

async function enviarPedido() {
    try {
        const { datos: pedido } = await api('/api/pedidos', { metodo: 'POST', cuerpo: cuerpoPedido() });
        lineas = [];
        guardar();
        formPedido.reset();
        dialogo.querySelector('[data-pedido-codigo]').textContent = pedido.codigo;
        dialogo.querySelector('[data-pedido-total]').textContent = precio(pedido.total);
        mostrarVista('hecho', true);
    } catch (error) {
        if (!(error instanceof ErrorApi)) {
            throw error;
        }
        switch (error.estado) {
            case 400:
                pintarErroresPedido(error.detalles.errores ?? {});
                mensaje('lineas', 'Revisa los datos marcados.');
                break;
            case 401:
                irAEntrar();
                break;
            case 403:
                // El token caducado ya lo resuelve api() (reintenta o lo convierte en 401):
                // aquí solo llega una cuenta que no es de cliente o un token que sigue sin valer
                mensaje('lineas', error.detalles.motivo === 'csrf'
                    ? error.message
                    : 'Las cuentas de la tienda no pueden hacer pedidos. Entra con una cuenta de cliente.');
                break;
            case 409:
                pintarSinStock(error.detalles.sin_stock ?? []);
                mensaje('lineas', 'Alguna talla ya no tiene stock suficiente. Revisa la cesta y vuelve a pedir.');
                break;
            default:
                mensaje('lineas', error.message);
        }
    }
}

/** Claves como «lineas.0.personalizacion.1.texto»: el número es la posición de la línea */
function pintarErroresPedido(errores) {
    Object.entries(errores).forEach(([clave, texto]) => {
        const partes = clave.match(/^lineas\.(\d+)/);
        if (partes && lineas[Number(partes[1])]) {
            const id = lineas[Number(partes[1])].variante_id;
            erroresLinea.set(id, erroresLinea.has(id) ? `${erroresLinea.get(id)} ${texto}` : texto);
        } else if (clave === 'lineas') {
            mensaje('lineas', texto);
        } else {
            errorCampo(formPedido, clave, texto);
        }
    });
    pintarLineas();
}

function pintarSinStock(sinStock) {
    sinStock.forEach((s) => {
        const linea = lineas.find((l) => l.variante_id === s.variante_id);
        if (!linea) {
            return;
        }
        // El stock que da el servidor es el bueno: el + de la línea ya no deja pasar de ahí
        linea.stock = s.disponible;
        // Se ajusta la cantidad a lo que queda: así basta con volver a pulsar «Hacer pedido»
        if (s.disponible > 0) {
            linea.cantidad = Math.min(linea.cantidad, s.disponible);
        }
        erroresLinea.set(linea.variante_id, s.disponible === 0
            ? 'Esta talla se ha agotado. Quítala para seguir.'
            : `Solo ${s.disponible === 1 ? 'queda 1 par' : `quedan ${s.disponible} pares`} de esta talla: hemos ajustado la cantidad.`);
    });
    guardar();
    pintarLineas();
}

// --- Entrar sin salir de la página ------------------------------------------------

// Mismo mensaje que el servidor (Validador::email) y la misma comprobación que
// acceso.js: algo@algo.algo, sin espacios. El servidor sigue siendo quien valida
const EMAIL_VALIDO = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
const MENSAJE_EMAIL = 'Email no válido';
// Solo se avisa del formato después de salir del campo o de intentar entrar,
// nunca mientras se escribe por primera vez
let emailTocado = false;

function revisarEmail() {
    const campo = formEntrar.elements.email;
    const valor = campo.value.trim();
    const destino = formEntrar.querySelector('[data-error="email"]');
    if (valor !== '' && !EMAIL_VALIDO.test(valor)) {
        errorCampo(formEntrar, 'email', MENSAJE_EMAIL);
        return;
    }
    // Ya escrito y bien formado: se quita el aviso; vacío, solo el de formato
    if (destino && (valor !== '' || destino.textContent === MENSAJE_EMAIL)) {
        destino.textContent = '';
        campo.removeAttribute('aria-invalid');
    }
}

function irAEntrar() {
    emailTocado = false;
    const registro = dialogo.querySelector('[data-cesta-registro]');
    // Tras crear la cuenta, la persona vuelve a la página donde estaba (la cesta sigue guardada)
    registro.href = `/registro?volver=${encodeURIComponent(window.location.pathname)}`;
    limpiarErrores(formEntrar);
    mensaje('entrar', '');
    mostrarVista('entrar');
    formEntrar.elements.email.focus();
}

async function entrar(evento) {
    evento.preventDefault();
    limpiarErrores(formEntrar);
    mensaje('entrar', '');

    const email = formEntrar.elements.email.value.trim();
    const password = formEntrar.elements.password.value;
    const emailMal = email !== '' && !EMAIL_VALIDO.test(email);
    if (email === '' || password === '' || emailMal) {
        if (email === '') errorCampo(formEntrar, 'email', 'Escribe tu email.');
        if (emailMal) errorCampo(formEntrar, 'email', MENSAJE_EMAIL);
        if (password === '') errorCampo(formEntrar, 'password', 'Escribe tu contraseña.');
        emailTocado = true;
        formEntrar.querySelector('[aria-invalid]')?.focus();
        return;
    }

    const boton = formEntrar.querySelector('[type="submit"]');
    boton.disabled = true;
    try {
        // publica: con el token de la página caducado, api() toma el nuevo y reintenta sin pedir sesión
        const { datos } = await api('/api/login', { metodo: 'POST', cuerpo: { email, password }, publica: true });
        // El servidor cambia el token al iniciar sesión: el de la página ya no vale
        fijarTokenCsrf(datos.csrf_token);
        sesion = { autenticado: true, usuario: datos.usuario, csrf_token: datos.csrf_token };
        actualizarCuenta(datos.usuario, datos.csrf_token);
        formEntrar.reset();

        mostrarVista('lineas');
        pintarLineas();
        if (datos.usuario.rol === 'cliente') {
            await rellenarDireccion();
            mensaje('lineas', `Hola, ${datos.usuario.nombre}. Revisa la dirección y confirma el pedido.`);
            formPedido.querySelector('[type="submit"]').focus();
        } else {
            mensaje('lineas', 'Las cuentas de la tienda no pueden hacer pedidos. Entra con una cuenta de cliente.');
            titulo.focus();
        }
    } catch (error) {
        const errores = error.detalles?.errores ?? {};
        Object.entries(errores).forEach(([campo, texto]) => errorCampo(formEntrar, campo, texto));
        if (error.estado === 401) {
            mensaje('entrar', 'El email o la contraseña no son correctos.');
        } else if (error.estado === 429) {
            const minutos = Math.ceil((error.detalles.reintentar_en_segundos ?? 900) / 60);
            mensaje('entrar', `Demasiados intentos. Vuelve a probar dentro de ${minutos} min.`);
        } else if (Object.keys(errores).length === 0) {
            mensaje('entrar', error.message);
        }
    } finally {
        boton.disabled = false;
    }
}

/**
 * La cabecera la pintó el servidor sin sesión: al entrar desde la cesta se
 * cambian «Entrar» y «Crear cuenta» por el nombre y el botón de salir, con el
 * token nuevo (cerrar sesión también es un POST protegido). El nombre enlaza a
 * /cuenta o a /panel con la misma regla que parciales/cabecera.php.
 */
function actualizarCuenta(usuario, token) {
    const cuenta = document.querySelector('.cuenta');
    if (!cuenta) {
        return;
    }
    cuenta.querySelectorAll(':scope > a, :scope > form, :scope > .cuenta__nombre').forEach((n) => n.remove());

    const form = crear('form', 'en-linea');
    form.method = 'post';
    form.action = '/logout';
    const oculto = crear('input');
    oculto.type = 'hidden';
    oculto.name = '_csrf';
    oculto.value = token;
    const salir = crear('button', '', 'Salir');
    salir.type = 'submit';
    form.append(oculto, salir);

    const nombre = crear('a', 'cuenta__nombre', usuario.nombre);
    nombre.href = usuario.rol === 'cliente' ? '/cuenta' : '/panel';
    cuenta.append(nombre, form);
}

// --- Arranque ------------------------------------------------------------------------

pintarContador();

if (dialogo) {
    prepararDialogo(dialogo);
    botones.forEach((boton) => boton.addEventListener('click', abrirCesta));
    dialogo.querySelectorAll('[data-cesta-cerrar]').forEach((b) => b.addEventListener('click', () => cerrarDialogo(dialogo)));
    dialogo.querySelectorAll('[data-cesta-ir]').forEach((b) => b.addEventListener('click', () => mostrarVista(b.dataset.cestaIr, true)));
    lista.addEventListener('click', alPulsarLinea);
    formPedido.addEventListener('submit', hacerPedido);
    formEntrar.addEventListener('submit', entrar);
    formEntrar.elements.email.addEventListener('blur', () => {
        emailTocado = true;
        revisarEmail();
    });
    formEntrar.elements.email.addEventListener('input', () => {
        if (emailTocado) {
            revisarEmail();
        }
    });
}
