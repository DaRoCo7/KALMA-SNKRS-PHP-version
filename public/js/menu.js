/*
 * Menú lateral de la cabecera. El HTML ya funciona sin JavaScript (el enlace
 * #menu lo muestra con :target); aquí se convierte en un diálogo modal de
 * verdad: showModal() deja la página de detrás inerte y el foco dentro del
 * panel, Escape y el clic en el fondo cierran con la animación de salida
 * (prepararDialogo, el mismo comportamiento que la cesta) y al cerrar el foco
 * vuelve al botón «Menú».
 */
import { prepararDialogo, cerrarDialogo } from './api.js';

const dialogo = document.getElementById('menu');
const enlace = document.querySelector('[data-menu-abrir]');

if (dialogo && enlace && typeof dialogo.showModal === 'function') {
    // Con JavaScript abre un panel, no navega: le corresponde ser un <button>
    // (se activa también con la barra espaciadora y se anuncia como tal)
    const boton = comoBoton(enlace);
    boton.setAttribute('aria-controls', 'menu');
    boton.setAttribute('aria-haspopup', 'dialog');
    boton.setAttribute('aria-expanded', 'false');

    // Lo mismo con «Cerrar», que sin JavaScript es un enlace a «#»
    const cerrar = comoBoton(dialogo.querySelector('[data-menu-cerrar]'));

    prepararDialogo(dialogo);

    boton.addEventListener('click', () => {
        ocultarCuentaSiYaHaySesion();
        dialogo.showModal();
        boton.setAttribute('aria-expanded', 'true');
    });

    cerrar.addEventListener('click', () => cerrarDialogo(dialogo));

    // «close» llega se cierre como se cierre (Escape, fondo, botón o un enlace)
    dialogo.addEventListener('close', () => {
        boton.setAttribute('aria-expanded', 'false');
        // Sin desplazar: si se ha ido a /#personalizacion, la página se queda ahí
        boton.focus({ preventScroll: true });
    });

    // Un enlace a otra parte de la misma página (/#personalizacion desde la
    // portada) no recarga: hay que cerrar el panel para que se vea el destino
    dialogo.querySelectorAll('a[href*="#"]:not([data-menu-cerrar])').forEach((a) => {
        a.addEventListener('click', () => {
            const destino = new URL(a.href);
            if (destino.pathname === window.location.pathname) {
                dialogo.close();
            }
        });
    });

    // Si alguien llega con #menu en la URL (enlace copiado sin JavaScript), se abre igual
    if (window.location.hash === '#menu') {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        boton.click();
    }
}

/** Cambia un enlace por un <button> con el mismo aspecto, contenido y atributos */
function comoBoton(enlace) {
    const boton = document.createElement('button');
    boton.type = 'button';
    [...enlace.attributes].forEach(({ name, value }) => {
        if (name !== 'href') {
            boton.setAttribute(name, value);
        }
    });
    boton.append(...enlace.childNodes);
    enlace.replaceWith(boton);
    return boton;
}

/**
 * Si se ha entrado desde la cesta, la cabecera ya muestra la cuenta (cesta.js
 * la cambia sin recargar) pero el menú sigue ofreciendo «Entrar»: se oculta
 * hasta la próxima carga, que ya lo pinta el servidor con la sesión.
 */
function ocultarCuentaSiYaHaySesion() {
    const cuentaMenu = dialogo.querySelector('.menu__cuenta');
    const haySesion = document.querySelector('.cuenta > .cuenta__nombre') !== null;
    if (cuentaMenu && haySesion && cuentaMenu.querySelector('a[href="/login"]')) {
        cuentaMenu.hidden = true;
    }
}
