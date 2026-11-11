/*
 * Formularios de login y registro.
 * - Al enviar, el botón se bloquea y cambia de texto: un doble clic no manda
 *   dos registros ni gasta dos intentos de login.
 * - Si el servidor devolvió errores, el foco va al primer campo que hay que
 *   corregir, así se puede escribir sin buscarlo.
 */
(function () {
    'use strict';

    document.querySelectorAll('form[data-enviando]').forEach(function (formulario) {
        var boton = formulario.querySelector('button[type="submit"]');
        if (!boton) {
            return;
        }
        var textoOriginal = boton.textContent;

        formulario.addEventListener('submit', function () {
            // Se bloquea en el siguiente ciclo: si se bloqueara aquí mismo, algunos
            // navegadores cancelarían el envío que acaba de empezar
            window.setTimeout(function () {
                boton.disabled = true;
                boton.textContent = formulario.dataset.enviando;
            }, 0);
        });

        // Al volver con «Atrás», el navegador puede restaurar la página tal cual
        // quedó (bloqueada): se devuelve el botón a su estado normal
        window.addEventListener('pageshow', function (evento) {
            if (evento.persisted) {
                boton.disabled = false;
                boton.textContent = textoOriginal;
            }
        });
    });

    // Con credenciales incorrectas el email ya está escrito: lo que toca repetir
    // es la contraseña, y la vista la marca con data-foco
    var primerError = document.querySelector('.acceso__formulario [data-foco]')
        || document.querySelector('.acceso__formulario [aria-invalid="true"]');
    if (primerError) {
        primerError.focus();
    }
})();
