/*
 * Llamadas a la API JSON (contrato, punto 9) y utilidades de formato que
 * comparten el catálogo, el panel de producto y la cesta.
 *
 * El token CSRF se guarda aquí y no se vuelve a leer del <meta> en cada
 * petición: al iniciar sesión el servidor lo cambia y el de la página deja de
 * valer, así que quien hace login debe pasar el nuevo con fijarTokenCsrf().
 */

let tokenCsrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export function fijarTokenCsrf(token) {
    tokenCsrf = token;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        meta.content = token;
    }
}

/** Error de la API con su código HTTP y los detalles del cuerpo {"error": ...} */
export class ErrorApi extends Error {
    constructor(estado, mensaje, detalles = {}) {
        super(mensaje);
        this.estado = estado;
        this.detalles = detalles ?? {};
    }
}

/**
 * Petición a la API. Devuelve el cuerpo ya leído ({datos, paginacion...}) o
 * lanza ErrorApi. Un fallo de red se convierte en ErrorApi con estado 0 para
 * que quien llama trate todos los errores igual.
 */
export async function api(ruta, { metodo = 'GET', cuerpo, senal } = {}) {
    const cabeceras = { Accept: 'application/json' };
    if (metodo !== 'GET') {
        cabeceras['X-CSRF-Token'] = tokenCsrf;
    }
    if (cuerpo !== undefined) {
        cabeceras['Content-Type'] = 'application/json';
    }

    let respuesta;
    try {
        respuesta = await fetch(ruta, {
            method: metodo,
            headers: cabeceras,
            body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
            credentials: 'same-origin',
            signal: senal,
        });
    } catch (error) {
        if (error.name === 'AbortError') {
            throw error;
        }
        throw new ErrorApi(0, 'No hay conexión con la tienda. Comprueba tu red y vuelve a intentarlo.');
    }

    if (respuesta.status === 204) {
        return {};
    }
    const json = await respuesta.json().catch(() => null);
    if (!respuesta.ok) {
        const error = json?.error ?? {};
        throw new ErrorApi(respuesta.status, error.mensaje ?? 'Algo ha fallado. Vuelve a intentarlo.', error.detalles);
    }
    return json ?? {};
}

const formatoPrecio = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });

export function precio(importe) {
    return formatoPrecio.format(importe);
}

/** 42.5 -> «42,5»; 42 -> «42» */
export function talla(valor) {
    return String(valor).replace('.', ',');
}

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/**
 * «2026-11-27» (o una fecha ISO completa) -> «27 de noviembre». Se lee el texto
 * y no se pasa por Date: así la zona horaria del navegador no mueve el día.
 */
export function fechaLarga(iso) {
    const [anio, mes, dia] = String(iso).slice(0, 10).split('-').map(Number);
    const hoy = new Date();
    const conAnio = anio !== hoy.getFullYear() ? ` de ${anio}` : '';
    return `${dia} de ${MESES[mes - 1]}${conAnio}`;
}

/** true si la fecha ISO (AAAA-MM-DD) todavía no ha llegado */
export function esFutura(iso) {
    const hoy = new Date();
    const local = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
    return String(iso).slice(0, 10) > local;
}

/**
 * Crea un elemento con clase y texto. Todo lo que llega de la API se pinta con
 * textContent a través de aquí, nunca con innerHTML.
 */
export function crear(etiqueta, clase = '', texto = null) {
    const elemento = document.createElement(etiqueta);
    if (clase) {
        elemento.className = clase;
    }
    if (texto !== null) {
        elemento.textContent = texto;
    }
    return elemento;
}

/** Quien pide menos movimiento no ve los avisos animados */
export function movimientoReducido() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Cierra un <dialog> con su animación de salida. La clase «cerrando» lleva la
 * transición (más corta que la de entrada); si no hay transición, o se pide
 * menos movimiento, se cierra a la vez.
 */
export function cerrarDialogo(dialogo, duracion = 150) {
    if (!dialogo.open || dialogo.classList.contains('cerrando')) {
        return;
    }
    if (movimientoReducido()) {
        dialogo.close();
        return;
    }
    dialogo.classList.add('cerrando');
    window.setTimeout(() => {
        dialogo.classList.remove('cerrando');
        dialogo.close();
    }, duracion);
}

/**
 * Comportamiento común de los paneles: Escape y el clic en el fondo cierran
 * con animación. El clic cae en el propio <dialog> solo fuera de su caja,
 * porque la caja lo ocupa entero.
 */
export function prepararDialogo(dialogo) {
    dialogo.addEventListener('cancel', (evento) => {
        evento.preventDefault();
        cerrarDialogo(dialogo);
    });
    // Se mira también dónde empezó la pulsación: seleccionar texto dentro de la
    // caja y soltar fuera no debe cerrar el panel
    let empiezaEnFondo = false;
    dialogo.addEventListener('pointerdown', (evento) => {
        empiezaEnFondo = evento.target === dialogo;
    });
    dialogo.addEventListener('click', (evento) => {
        if (evento.target === dialogo && empiezaEnFondo) {
            cerrarDialogo(dialogo);
        }
    });
}
