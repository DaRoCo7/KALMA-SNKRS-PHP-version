/*
 * Comportamiento de la portada, debajo del hero (la cinta de lanzamientos es
 * solo CSS):
 *   - la entrada de cada pieza al asomar por abajo,
 *   - la prueba del nombre bordado.
 *
 * Sin este archivo la página se lee igual: nada llega animado y la muestra del
 * bordado enseña «TU NOMBRE».
 */

// --- Entradas ----------------------------------------------------------------

function iniciarEntradas() {
    const piezas = document.querySelectorAll('[data-entrada], [data-par]');
    if (piezas.length === 0) return;

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
    }, { rootMargin: '0px 0px -12% 0px' });

    for (const pieza of piezas) observador.observe(pieza);
}

// --- Nombre bordado ------------------------------------------------------------

function iniciarBordado(bloque) {
    const entrada = bloque.querySelector('[data-bordado-entrada]');
    const texto = bloque.querySelector('[data-bordado-texto]');
    if (!entrada || !texto) return;

    const porDefecto = texto.textContent;
    const ANCHO = 560;   // ancho útil del dibujo, en sus propias unidades

    entrada.addEventListener('input', () => {
        const nombre = entrada.value.trim().toUpperCase();
        texto.textContent = nombre === '' ? porDefecto : nombre;
        // Los nombres largos se estrechan para caber en vez de salirse del dibujo
        texto.removeAttribute('textLength');
        texto.removeAttribute('lengthAdjust');
        if (texto.getComputedTextLength() > ANCHO) {
            texto.setAttribute('textLength', String(ANCHO));
            texto.setAttribute('lengthAdjust', 'spacingAndGlyphs');
        }
    });
}

for (const bloque of document.querySelectorAll('[data-bordado]')) iniciarBordado(bloque);
iniciarEntradas();
