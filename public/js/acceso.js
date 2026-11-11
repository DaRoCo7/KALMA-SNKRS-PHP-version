/*
 * Formularios de login y registro.
 * - Al enviar, el botón se bloquea y cambia de texto: un doble clic no manda
 *   dos registros ni gasta dos intentos de login.
 * - Si el servidor devolvió errores, el foco va al primer campo que hay que
 *   corregir, así se puede escribir sin buscarlo.
 * - El formato del email se comprueba al salir del campo y al enviar.
 */
(function () {
    'use strict';

    // Formato del email en el navegador, con el mismo mensaje que el servidor
    // (Validador::email). Es solo una ayuda para no esperar a la recarga: el
    // formulario lleva novalidate y quien valida de verdad sigue siendo el servidor
    var EMAIL_VALIDO = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
    var MENSAJE_EMAIL = 'Email no válido';

    function idsDescritos(campo) {
        return (campo.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
    }

    /** Pinta el error igual que la vista PHP: campo--error, campo__error, aria-invalid y aria-describedby */
    function mostrarErrorEmail(campo) {
        var caja = campo.closest('.campo');
        var idError = 'error-' + campo.name;
        var error = document.getElementById(idError);
        if (!error) {
            error = document.createElement('p');
            error.className = 'campo__error';
            error.id = idError;
            caja.appendChild(error);
        }
        error.textContent = MENSAJE_EMAIL;
        caja.classList.add('campo--error');
        campo.setAttribute('aria-invalid', 'true');
        var ids = idsDescritos(campo);
        if (ids.indexOf(idError) === -1) {
            ids.push(idError);
            campo.setAttribute('aria-describedby', ids.join(' '));
        }
    }

    /**
     * Quita solo el error de formato: otro que haya dado el servidor («ya existe
     * una cuenta con ese email», credenciales) habla del envío anterior y se queda
     */
    function quitarErrorEmail(campo) {
        var idError = 'error-' + campo.name;
        var error = document.getElementById(idError);
        if (!error || error.textContent !== MENSAJE_EMAIL) {
            return;
        }
        error.remove();
        campo.closest('.campo').classList.remove('campo--error');
        var ids = idsDescritos(campo).filter(function (id) { return id !== idError; });
        if (ids.length > 0) {
            campo.setAttribute('aria-describedby', ids.join(' '));
        } else {
            campo.removeAttribute('aria-describedby');
        }
        // Con credenciales incorrectas el campo sigue marcado por el aviso general
        if (ids.indexOf('aviso-acceso') === -1) {
            campo.removeAttribute('aria-invalid');
        }
    }

    /** true si el email está vacío (eso lo dice el servidor) o bien formado */
    function revisarEmail(campo) {
        var valor = campo.value.trim();
        if (valor !== '' && !EMAIL_VALIDO.test(valor)) {
            mostrarErrorEmail(campo);
            return false;
        }
        quitarErrorEmail(campo);
        return true;
    }

    document.querySelectorAll('.acceso__formulario input[type="email"]').forEach(function (campo) {
        // Mientras se escribe por primera vez no se molesta: el aviso aparece al
        // salir del campo o al intentar enviar, y desde entonces se corrige en vivo
        var tocado = false;
        campo.addEventListener('blur', function () {
            tocado = true;
            revisarEmail(campo);
        });
        campo.addEventListener('input', function () {
            if (tocado) {
                revisarEmail(campo);
            }
        });
        campo.form.addEventListener('submit', function (evento) {
            tocado = true;
            if (!revisarEmail(campo)) {
                evento.preventDefault();
                campo.focus();
            }
        });
    });

    document.querySelectorAll('form[data-enviando]').forEach(function (formulario) {
        var boton = formulario.querySelector('button[type="submit"]');
        if (!boton) {
            return;
        }
        var textoOriginal = boton.textContent;

        formulario.addEventListener('submit', function (evento) {
            // Si el email no pasa la comprobación de arriba no hay envío: el botón sigue activo
            if (evento.defaultPrevented) {
                return;
            }
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
