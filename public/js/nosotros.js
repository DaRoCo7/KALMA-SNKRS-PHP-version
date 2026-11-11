/*
 * Comportamiento de «Sobre nosotros»:
 *   - la entrada de cada pieza al asomar por abajo,
 *   - el par fijo del escenario, que cambia cuando el capítulo correspondiente
 *     cruza la mitad de la pantalla.
 *
 * Sin este archivo la página se lee entera: nada llega animado y el escenario
 * enseña siempre el primer par.
 */

// --- Entradas ----------------------------------------------------------------

function iniciarEntradas() {
    const piezas = document.querySelectorAll('[data-entrada]');
    if (piezas.length === 0) return;

    // Sin observador no hay quien las muestre: mejor enseñarlas ya
    if (!('IntersectionObserver' in window)) {
        for (const pieza of piezas) pieza.classList.add('ha-entrado');
        return;
    }

    const observador = new IntersectionObserver((entradas) => {
        // Las que asoman a la vez llegan escalonadas, en orden de lectura
        let turno = 0;
        for (const entrada of entradas) {
            if (!entrada.isIntersecting) continue;
            entrada.target.style.setProperty('--retraso', `${turno * 70}ms`);
            entrada.target.classList.add('ha-entrado');
            observador.unobserve(entrada.target);   // una sola vez
            turno++;
        }
    }, { rootMargin: '0px 0px -10% 0px' });

    for (const pieza of piezas) observador.observe(pieza);
}

// --- Escenario -----------------------------------------------------------------

function iniciarEscenario(escenario) {
    const escenas = new Map();
    for (const escena of escenario.querySelectorAll('[data-escena]')) {
        escenas.set(escena.dataset.escena, escena);
    }
    const capitulos = escenario.querySelectorAll('[data-capitulo]');
    if (escenas.size < 2 || capitulos.length === 0 || !('IntersectionObserver' in window)) return;

    let actual = 'inicio';
    const mostrar = (clave) => {
        if (clave === actual || !escenas.has(clave)) return;
        escenas.get(actual)?.classList.remove('es-actual');
        escenas.get(clave).classList.add('es-actual');
        actual = clave;
    };

    // Una franja fina en mitad de la pantalla: manda el capítulo que la cruza,
    // así el cambio llega cuando de verdad se está leyendo ese capítulo
    const observador = new IntersectionObserver((entradas) => {
        for (const entrada of entradas) {
            if (entrada.isIntersecting) mostrar(entrada.target.dataset.capitulo);
        }
    }, { rootMargin: '-45% 0px -45% 0px' });

    for (const capitulo of capitulos) observador.observe(capitulo);
}

for (const escenario of document.querySelectorAll('[data-escenario]')) iniciarEscenario(escenario);
iniciarEntradas();
