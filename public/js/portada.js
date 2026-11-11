/*
 * Comportamiento de la portada, debajo del hero:
 *   - el carrusel de lanzamientos y la palabra que cambia con cada par,
 *   - la entrada de cada pieza al asomar por abajo,
 *   - la prueba del nombre bordado.
 *
 * Sin este archivo la página se lee igual: el carrusel es una fila que se
 * desplaza a mano, nada llega animado y la muestra del bordado enseña «TU NOMBRE».
 */

// --- Carrusel de lanzamientos --------------------------------------------------

const PAUSA = 5000;        // ms que se queda cada par cuando avanza solo
const ARRASTRE = 48;       // px que hay que arrastrar para cambiar de par

function iniciarCarrusel(seccion) {
    const pista = seccion.querySelector('[data-carrusel-pista]');
    const diapositivas = [...seccion.querySelectorAll('[data-diapositiva]')];
    const palabras = [...seccion.querySelectorAll('[data-palabra]')];
    const mandos = seccion.querySelector('[data-carrusel-mandos]');
    const cuenta = seccion.querySelector('[data-carrusel-cuenta]');
    if (!pista || diapositivas.length < 2) return;

    const movimientoReducido = window.matchMedia('(prefers-reduced-motion: reduce)');
    let actual = 0;
    let temporizador = 0;
    let enPantalla = false;
    let ocupado = false;     // el ratón o el foco están dentro: no se le cambia el par a quien lo mira

    // Las que no se ven tampoco existen para el teclado ni el lector de pantalla
    diapositivas.forEach((d, i) => { d.inert = i !== 0; });
    if (mandos) mandos.hidden = false;

    function ir(destino, sentido) {
        const siguiente = (destino + diapositivas.length) % diapositivas.length;
        if (siguiente === actual) return;

        const sale = diapositivas[actual];
        const entra = diapositivas[siguiente];
        pista.style.setProperty('--sentido', String(sentido));

        // Si se pulsa rápido, la que estaba saliendo se retira sin esperar
        for (const d of diapositivas) d.classList.remove('es-saliente');

        sale.classList.remove('es-activa');
        sale.classList.add('es-saliente');
        sale.inert = true;
        sale.addEventListener('transitionend', () => sale.classList.remove('es-saliente'), { once: true });

        // Leer la posición obliga al navegador a colocar la que entra en su
        // punto de partida antes de animarla hacia el centro.
        void entra.offsetWidth;
        entra.classList.add('es-activa');
        entra.inert = false;

        // La palabra de la casa acompaña al par: se repiten en orden
        if (palabras.length > 0) {
            palabras[actual % palabras.length].classList.remove('es-activa');
            palabras[siguiente % palabras.length].classList.add('es-activa');
        }
        if (cuenta) cuenta.textContent = `${siguiente + 1} / ${diapositivas.length}`;

        actual = siguiente;
    }

    function parar() {
        clearTimeout(temporizador);
        temporizador = 0;
    }

    function programar() {
        parar();
        // Solo avanza solo si alguien lo está viendo y no ha pedido menos movimiento
        if (!enPantalla || ocupado || document.hidden || movimientoReducido.matches) return;
        temporizador = setTimeout(() => {
            ir(actual + 1, 1);
            programar();
        }, PAUSA);
    }

    // Una orden a mano cambia de par y reinicia la espera
    function aMano(paso) {
        ir(actual + paso, paso);
        programar();
    }

    seccion.querySelector('[data-carrusel-anterior]')?.addEventListener('click', () => aMano(-1));
    seccion.querySelector('[data-carrusel-siguiente]')?.addEventListener('click', () => aMano(1));

    // Arrastre con el dedo o el ratón: hacia la izquierda avanza
    let inicioX = null;
    pista.addEventListener('pointerdown', (evento) => { inicioX = evento.clientX; });
    pista.addEventListener('pointerup', (evento) => {
        if (inicioX === null) return;
        const recorrido = evento.clientX - inicioX;
        inicioX = null;
        if (Math.abs(recorrido) >= ARRASTRE) aMano(recorrido < 0 ? 1 : -1);
    });
    pista.addEventListener('pointercancel', () => { inicioX = null; });

    const ocupar = () => { ocupado = true; parar(); };
    const liberar = () => { ocupado = false; programar(); };
    seccion.addEventListener('pointerenter', ocupar);
    seccion.addEventListener('pointerleave', liberar);
    seccion.addEventListener('focusin', ocupar);
    seccion.addEventListener('focusout', liberar);

    document.addEventListener('visibilitychange', programar);
    movimientoReducido.addEventListener('change', programar);
    new IntersectionObserver(([entrada]) => {
        enPantalla = entrada.isIntersecting;
        programar();
    }, { threshold: 0.4 }).observe(seccion);
}

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

for (const seccion of document.querySelectorAll('[data-carrusel]')) iniciarCarrusel(seccion);
for (const bloque of document.querySelectorAll('[data-bordado]')) iniciarBordado(bloque);
iniciarEntradas();
