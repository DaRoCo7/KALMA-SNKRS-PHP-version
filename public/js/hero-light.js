/*
 * Hero de la portada: una luz cálida que se mueve con el puntero y recorta la
 * silueta de una zapatilla, con rayos que la zapatilla tapa.
 *
 * La foto no se pinta nunca tal cual: solo sirve para fabricar una máscara de
 * tres canales (silueta nítida y dos desenfoques) que el shader usa para saber
 * dónde hay zapatilla, dónde está el borde y cuánta luz se pierde al cruzarla.
 *
 * Módulo ES sin dependencias. Todo queda dentro de la sección: mide la sección
 * (no la ventana), escucha el puntero solo sobre ella y devuelve una función
 * que lo desmonta todo.
 */

const VERTICES = `#version 300 es
// Un triángulo que cubre la pantalla, sacado del número de vértice: no hace
// falta ningún búfer de geometría.
void main() {
    vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
    gl_Position = vec4(p * 2.0 - 1.0, 0.0, 1.0);
}`;

const FRAGMENTOS = `#version 300 es
precision highp float;

uniform sampler2D uMask;   // r: silueta nítida · g: desenfoque fino · b: desenfoque ancho
uniform sampler2D uNoise;  // ruido azul 64x64
uniform vec2  uRes;
uniform float uAspect;
uniform vec2  uLight;      // posición de la luz, centrada y corregida por el aspecto
uniform float uTime;
uniform float uReveal;

out vec4 salida;

const int   PASOS    = 36;    // muestras a lo largo de cada rayo
const int   TOMAS    = 5;     // puntos de la barra de luz
const float BARRA    = 0.28;  // alto de la barra de luz
const float DENSIDAD = 26.0;  // cuánta luz se come la zapatilla

vec3 mascara(vec2 p) {
    return texture(uMask, p / vec2(uAspect, 1.0) + 0.5).rgb;
}

float caida(float d) {
    float r = max(d - 0.03, 0.0);
    return exp(-r * 1.8) / (1.0 + r * r * 24.0);
}

void main() {
    vec2 uv = gl_FragCoord.xy / uRes;
    vec2 p  = (uv - 0.5) * vec2(uAspect, 1.0);

    // El ruido cambia de sitio 24 veces por segundo: desplaza el inicio de cada
    // rayo para que las 36 muestras no dibujen bandas.
    vec2  salto = fract(vec2(0.7548, 0.5698) * floor(uTime * 24.0)) * 64.0;
    float ruido = texture(uNoise, (gl_FragCoord.xy + floor(salto)) / 64.0).r;

    vec3 m = mascara(p);

    // --- Luz que llega a este punto desde la barra, restando lo que tapa la zapatilla
    float luz = 0.0;
    float pesos = 0.0;
    for (int t = 0; t < TOMAS; t++) {
        float ft = float(t) / float(TOMAS - 1) - 0.5;
        float peso = 1.0 - abs(ft) * 1.2;
        vec2  foco = uLight + vec2(0.0, ft * BARRA);
        vec2  rayo = foco - p;
        float largo = length(rayo);

        float grosor = 0.0;
        for (int i = 0; i < PASOS; i++) {
            float s = (float(i) + ruido) / float(PASOS);
            grosor += mascara(p + rayo * s).g;
        }
        grosor *= largo / float(PASOS);

        luz += peso * caida(largo) * exp(-grosor * DENSIDAD);
        pesos += peso;
    }
    luz /= pesos;

    // --- Color: crema cerca de la luz, naranja lejos
    vec2  haciaLuz = uLight - p;
    float distancia = length(haciaLuz);
    // Luz blanca neutra, el mismo blanco que el resto de la página: sin tinte
    // cálido, solo pierde intensidad con la distancia.
    vec3  tono = vec3(1.0);
    float fuerza = 2.6 * uReveal;
    float cerca = caida(distancia);
    vec2  dirLuz = haciaLuz / max(distancia, 1e-4);

    // --- Bordes: el degradado de la máscara dice hacia dónde mira cada borde
    vec2 px = vec2(uAspect, 1.0) * 1.5 / uRes;
    vec2 gFino = vec2(
        mascara(p + vec2(px.x, 0.0)).g - mascara(p - vec2(px.x, 0.0)).g,
        mascara(p + vec2(0.0, px.y)).g - mascara(p - vec2(0.0, px.y)).g);
    vec2 pxAncho = px * 4.0;
    vec2 gAncho = vec2(
        mascara(p + vec2(pxAncho.x, 0.0)).b - mascara(p - vec2(pxAncho.x, 0.0)).b,
        mascara(p + vec2(0.0, pxAncho.y)).b - mascara(p - vec2(0.0, pxAncho.y)).b);
    vec2 nFino  = -gFino  / max(length(gFino),  1e-4);
    vec2 nAncho = -gAncho / max(length(gAncho), 1e-4);

    // Canto: la franja estrecha del borde, encendida solo si mira a la luz
    float franja = m.g * (1.0 - m.g) * 4.0;
    float canto = franja * max(dot(nFino, dirLuz), 0.0);
    // Bisel: los primeros milímetros hacia dentro, para que la pieza tenga volumen
    float dentro = m.r * (1.0 - smoothstep(0.55, 1.0, m.b));
    float bisel = dentro * max(dot(nAncho, dirLuz), 0.0);

    // --- Composición
    // El fondo es negro puro aquí: la gamma del final y el grano lo dejan en torno a #010101
    vec3 fondo = vec3(0.0);
    vec3 aire  = tono * luz * fuerza;                           // rayos en el aire
    vec3 halo  = tono * luz * fuerza * m.b * 0.55;              // resplandor pegado a la pieza
    vec3 losa  = vec3(0.0004) + tono * fuerza * cerca * bisel * 0.55;

    vec3 col = mix(fondo + aire + halo, losa, m.r);
    col += tono * fuerza * cerca * canto * 1.5;

    // El foco no se dibuja: solo se ve lo que ilumina. La barra de luz sigue
    // existiendo para el cálculo (da sombras más suaves que un punto).

    // --- Salida: compresión de tonos, gamma, viñeta y grano
    col = col / (1.0 + col * 0.8);
    col = pow(col, vec3(1.0 / 2.2));
    vec2 c = uv - 0.5;
    col *= 1.0 - dot(c, c) * 0.42;
    float grano = texture(uNoise, (gl_FragCoord.xy + floor(salto.yx) + 17.0) / 64.0).r;
    col += (grano - 0.5) * 0.028;

    salida = vec4(col, 1.0);
}`;

/**
 * Ruido azul 64x64 hecho por código: se parte de ruido blanco y se le quitan
 * varias veces las frecuencias bajas (el valor menos su desenfoque), repartiendo
 * de nuevo los valores de 0 a 255. El resultado no tiene grumos y se repite sin
 * costuras porque el desenfoque da la vuelta por los bordes.
 */
function crearRuidoAzul(lado = 64) {
    const total = lado * lado;
    let semilla = 0x9e3779b9;
    const azar = () => {             // mulberry32: mismo ruido en cada carga
        semilla = (semilla + 0x6d2b79f5) | 0;
        let t = Math.imul(semilla ^ (semilla >>> 15), 1 | semilla);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };

    let valores = new Float32Array(total);
    for (let i = 0; i < total; i++) valores[i] = azar();

    const sigma = 1.5;
    const radio = 4;
    const nucleo = [];
    for (let k = -radio; k <= radio; k++) nucleo.push(Math.exp(-(k * k) / (2 * sigma * sigma)));
    const suma = nucleo.reduce((a, b) => a + b, 0);

    const desenfocar = (origen, horizontal) => {
        const destino = new Float32Array(total);
        for (let y = 0; y < lado; y++) {
            for (let x = 0; x < lado; x++) {
                let acumulado = 0;
                for (let k = -radio; k <= radio; k++) {
                    const xx = horizontal ? (x + k + lado) % lado : x;
                    const yy = horizontal ? y : (y + k + lado) % lado;
                    acumulado += origen[yy * lado + xx] * nucleo[k + radio];
                }
                destino[y * lado + x] = acumulado / suma;
            }
        }
        return destino;
    };

    const orden = new Uint16Array(total);
    for (let vuelta = 0; vuelta < 4; vuelta++) {
        const suave = desenfocar(desenfocar(valores, true), false);
        for (let i = 0; i < total; i++) valores[i] -= suave[i];
        // Reparto uniforme: cada valor pasa a ser su puesto en la clasificación
        for (let i = 0; i < total; i++) orden[i] = i;
        const copia = valores;
        orden.sort((a, b) => copia[a] - copia[b]);
        const repartido = new Float32Array(total);
        for (let i = 0; i < total; i++) repartido[orden[i]] = i / (total - 1);
        valores = repartido;
    }

    const bytes = new Uint8Array(total);
    for (let i = 0; i < total; i++) bytes[i] = Math.round(valores[i] * 255);
    return bytes;
}

function compilar(gl, tipo, codigo) {
    const shader = gl.createShader(tipo);
    gl.shaderSource(shader, codigo);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        const motivo = gl.getShaderInfoLog(shader);
        gl.deleteShader(shader);
        throw new Error(`El shader no compila: ${motivo}`);
    }
    return shader;
}

/**
 * Arranca el efecto en una sección. Devuelve la función que lo desmonta.
 * Si algo falla (sin WebGL2, sin imagen, shader que no compila) deja la
 * imagen estática a la vista y no dibuja nada.
 */
export function iniciarHeroLight(seccion) {
    const lienzo = seccion.querySelector('[data-hero-lienzo]');
    const imagen = seccion.querySelector('[data-hero-imagen]');
    if (!lienzo || !imagen) return () => {};

    // Un solo bucle por sección aunque el módulo se cargue dos veces
    if (seccion.dataset.heroActivo === '1') return () => {};
    seccion.dataset.heroActivo = '1';

    const mostrarEstatica = () => {
        seccion.classList.add('hero-light--estatico');
        imagen.hidden = false;
    };

    const gl = lienzo.getContext('webgl2', {
        antialias: false,
        premultipliedAlpha: false,
        alpha: false,
        powerPreference: 'high-performance',
    });
    if (!gl) {
        mostrarEstatica();
        return () => { delete seccion.dataset.heroActivo; };
    }

    const movimientoReducido = window.matchMedia('(prefers-reduced-motion: reduce)');

    let programa = null;
    let texturaMascara = null;
    let texturaRuido = null;
    let uniformes = {};
    let cuadro = 0;                 // id del requestAnimationFrame en curso
    let destruido = false;
    let listo = false;
    let visible = true;
    let mascaraPendiente = true;
    let ancho = 1, alto = 1, aspecto = 1;
    let caja = seccion.getBoundingClientRect();

    // Luz y destino en coordenadas centradas y corregidas por el aspecto
    const luz = { x: 0.06, y: 0.16 };
    const destino = { x: 0.06, y: 0.16 };
    let transcurrido = 0;           // segundos dibujados (no cuenta el tiempo en pausa)
    let ultimaEntrada = -4;         // sin tocar nada, la deriva empieza desde el principio
    let anterior = 0;

    const lienzoMascara = document.createElement('canvas');
    const lienzoCapa = document.createElement('canvas');
    const lienzoPequeno = document.createElement('canvas');

    function medir() {
        caja = seccion.getBoundingClientRect();
        const densidad = Math.min(window.devicePixelRatio || 1, 1.25);
        const nuevoAncho = Math.max(1, Math.round(caja.width * densidad));
        const nuevoAlto = Math.max(1, Math.round(caja.height * densidad));
        if (nuevoAncho !== ancho || nuevoAlto !== alto) {
            ancho = nuevoAncho;
            alto = nuevoAlto;
            mascaraPendiente = true;
        }
        aspecto = caja.width / Math.max(caja.height, 1);
    }

    /** Dibuja la silueta en un color, nítida o desenfocada, sobre lienzoCapa. */
    function pintarCapa(color, desenfoque, x, y, w, h) {
        const capa = lienzoCapa.getContext('2d');
        capa.globalCompositeOperation = 'source-over';
        capa.clearRect(0, 0, ancho, alto);

        if (desenfoque <= 0) {
            capa.drawImage(imagen, x, y, w, h);
        } else if (typeof capa.filter === 'string') {
            capa.filter = `blur(${desenfoque}px)`;
            capa.drawImage(imagen, x, y, w, h);
            capa.filter = 'none';
        } else {
            // Navegadores sin filtros en canvas: encoger y volver a ampliar
            // difumina de forma parecida.
            const factor = Math.max(1, desenfoque / 1.5);
            const pw = Math.max(1, Math.round(ancho / factor));
            const ph = Math.max(1, Math.round(alto / factor));
            lienzoPequeno.width = pw;
            lienzoPequeno.height = ph;
            const pequeno = lienzoPequeno.getContext('2d');
            pequeno.drawImage(imagen, x / factor, y / factor, w / factor, h / factor);
            capa.imageSmoothingEnabled = true;
            capa.imageSmoothingQuality = 'high';
            capa.drawImage(lienzoPequeno, 0, 0, ancho, alto);
        }

        // Se queda solo con la forma (el alfa) y la tiñe del color del canal
        capa.globalCompositeOperation = 'source-in';
        capa.fillStyle = color;
        capa.fillRect(0, 0, ancho, alto);
    }

    function construirMascara() {
        lienzo.width = ancho;
        lienzo.height = alto;
        lienzoMascara.width = ancho;
        lienzoMascara.height = alto;
        lienzoCapa.width = ancho;
        lienzoCapa.height = alto;

        // La zapatilla entra entera: como mucho el 70 % del ancho y el 60 % del alto
        const escala = Math.min(
            (ancho * 0.7) / imagen.naturalWidth,
            (alto * 0.6) / imagen.naturalHeight,
        );
        const w = imagen.naturalWidth * escala;
        const h = imagen.naturalHeight * escala;
        const x = (ancho - w) / 2;
        const y = (alto - h) / 2;

        const mascara = lienzoMascara.getContext('2d');
        mascara.globalCompositeOperation = 'source-over';
        mascara.fillStyle = '#000';
        mascara.fillRect(0, 0, ancho, alto);

        // Tres capas sumadas, una por canal
        const capas = [
            ['#f00', 0],
            ['#0f0', alto * 0.006],
            ['#00f', alto * 0.03],
        ];
        mascara.globalCompositeOperation = 'lighter';
        for (const [color, desenfoque] of capas) {
            pintarCapa(color, desenfoque, x, y, w, h);
            mascara.drawImage(lienzoCapa, 0, 0);
        }

        gl.bindTexture(gl.TEXTURE_2D, texturaMascara);
        // El canvas tiene el origen arriba y WebGL abajo: se voltea solo aquí
        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, lienzoMascara);
        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);

        gl.viewport(0, 0, ancho, alto);
        mascaraPendiente = false;
    }

    function prepararGl() {
        programa = gl.createProgram();
        const vertices = compilar(gl, gl.VERTEX_SHADER, VERTICES);
        const fragmentos = compilar(gl, gl.FRAGMENT_SHADER, FRAGMENTOS);
        gl.attachShader(programa, vertices);
        gl.attachShader(programa, fragmentos);
        gl.linkProgram(programa);
        gl.deleteShader(vertices);
        gl.deleteShader(fragmentos);
        if (!gl.getProgramParameter(programa, gl.LINK_STATUS)) {
            throw new Error(`El programa no enlaza: ${gl.getProgramInfoLog(programa)}`);
        }
        gl.useProgram(programa);
        for (const nombre of ['uMask', 'uNoise', 'uRes', 'uAspect', 'uLight', 'uTime', 'uReveal']) {
            uniformes[nombre] = gl.getUniformLocation(programa, nombre);
        }

        texturaMascara = gl.createTexture();
        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, texturaMascara);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

        texturaRuido = gl.createTexture();
        gl.activeTexture(gl.TEXTURE1);
        gl.bindTexture(gl.TEXTURE_2D, texturaRuido);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.R8, 64, 64, 0, gl.RED, gl.UNSIGNED_BYTE, crearRuidoAzul(64));
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.REPEAT);

        gl.activeTexture(gl.TEXTURE0);
        gl.uniform1i(uniformes.uMask, 0);
        gl.uniform1i(uniformes.uNoise, 1);
    }

    function dibujar(ahora) {
        cuadro = 0;
        if (destruido || !visible) return;

        const dt = Math.min((ahora - anterior) / 1000, 0.05);
        anterior = ahora;
        transcurrido += dt;

        if (mascaraPendiente) construirMascara();

        const reducido = movimientoReducido.matches;

        // Pasados 4 s sin entrada, la luz empieza a pasearse sola; tarda 6 s en
        // llegar al paseo completo para que no haya un tirón.
        let objetivoX = destino.x;
        let objetivoY = destino.y;
        if (!reducido) {
            const quieto = transcurrido - ultimaEntrada;
            const deriva = Math.min(Math.max((quieto - 4) / 6, 0), 1);
            objetivoX += Math.sin(transcurrido * 0.23) * 0.22 * deriva;
            objetivoY += Math.sin(transcurrido * 0.17 + 1.3) * 0.12 * deriva;
        }

        // Seguimiento amortiguado: no depende de los fotogramas por segundo
        const seguimiento = 1 - Math.exp(-(reducido ? 30 : 3.2) * dt);
        luz.x += (objetivoX - luz.x) * seguimiento;
        luz.y += (objetivoY - luz.y) * seguimiento;

        const entrada = reducido ? 1 : Math.min(transcurrido / 1.6, 1) ** 2;

        gl.uniform2f(uniformes.uRes, ancho, alto);
        gl.uniform1f(uniformes.uAspect, aspecto);
        gl.uniform2f(uniformes.uLight, luz.x, luz.y);
        gl.uniform1f(uniformes.uTime, transcurrido);
        gl.uniform1f(uniformes.uReveal, entrada);
        gl.drawArrays(gl.TRIANGLES, 0, 3);

        cuadro = requestAnimationFrame(dibujar);
    }

    function reanudar() {
        if (destruido || !listo || !visible || cuadro) return;
        anterior = performance.now();
        cuadro = requestAnimationFrame(dibujar);
    }

    function apuntar(clientX, clientY) {
        // Relativo a la sección: la página puede estar desplazada o tener cabecera
        caja = seccion.getBoundingClientRect();
        destino.x = ((clientX - caja.left) / caja.width - 0.5) * aspecto;
        destino.y = 0.5 - (clientY - caja.top) / caja.height;
        ultimaEntrada = transcurrido;
    }

    const alPuntero = (evento) => apuntar(evento.clientX, evento.clientY);
    const alTocar = (evento) => {
        const toque = evento.touches[0];
        if (toque) apuntar(toque.clientX, toque.clientY);
    };
    const alPerderContexto = (evento) => {
        evento.preventDefault();
        destruir();
        mostrarEstatica();
    };

    const observadorTamano = new ResizeObserver(() => medir());
    // Fuera de pantalla no se dibuja: el shader es caro y nadie lo está viendo
    const observadorVista = new IntersectionObserver(([entrada]) => {
        visible = entrada.isIntersecting;
        if (visible) reanudar();
        else if (cuadro) { cancelAnimationFrame(cuadro); cuadro = 0; }
    });

    function destruir() {
        if (destruido) return;
        destruido = true;
        if (cuadro) cancelAnimationFrame(cuadro);
        cuadro = 0;
        observadorTamano.disconnect();
        observadorVista.disconnect();
        seccion.removeEventListener('pointermove', alPuntero);
        seccion.removeEventListener('pointerdown', alPuntero);
        seccion.removeEventListener('touchmove', alTocar);
        lienzo.removeEventListener('webglcontextlost', alPerderContexto);
        window.removeEventListener('pagehide', destruir);
        if (texturaMascara) gl.deleteTexture(texturaMascara);
        if (texturaRuido) gl.deleteTexture(texturaRuido);
        if (programa) gl.deleteProgram(programa);
        gl.getExtension('WEBGL_lose_context')?.loseContext();
        delete seccion.dataset.heroActivo;
    }

    async function arrancar() {
        try {
            // La máscara sale de la foto: sin ella cargada no hay nada que iluminar
            if (!imagen.complete || imagen.naturalWidth === 0) {
                await new Promise((resolver, rechazar) => {
                    imagen.addEventListener('load', resolver, { once: true });
                    imagen.addEventListener('error', () => rechazar(new Error('No carga la imagen del hero')), { once: true });
                });
            }
            if (destruido) return;

            prepararGl();
            medir();
            construirMascara();

            observadorTamano.observe(seccion);
            observadorVista.observe(seccion);
            seccion.addEventListener('pointermove', alPuntero);
            seccion.addEventListener('pointerdown', alPuntero);
            seccion.addEventListener('touchmove', alTocar, { passive: true });
            lienzo.addEventListener('webglcontextlost', alPerderContexto);
            window.addEventListener('pagehide', destruir);

            listo = true;
            seccion.classList.add('hero-light--activo');
            reanudar();
        } catch (error) {
            console.error(error);
            destruir();
            mostrarEstatica();
        }
    }

    arrancar();
    return destruir;
}

for (const seccion of document.querySelectorAll('[data-hero-light]')) {
    iniciarHeroLight(seccion);
}
