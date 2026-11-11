<?php
/**
 * Panel lateral de la cesta, común a todas las páginas (lo incluye la cabecera).
 * Aquí solo está el esqueleto: las líneas las pinta public/js/cesta.js a partir
 * de lo guardado en el navegador. Tiene tres vistas que se alternan sin salir
 * de la página: las líneas con el envío, el formulario para entrar y el pedido hecho.
 */
?>
    <dialog class="cesta" id="cesta" aria-labelledby="cesta-titulo">
        <div class="cesta__caja">
            <div class="cesta__cabecera">
                <h2 class="cesta__titulo" id="cesta-titulo" tabindex="-1">Cesta</h2>
                <button class="boton boton--icono boton--claro" type="button" data-cesta-cerrar aria-label="Cerrar la cesta">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>

            <div class="cesta__vista" data-vista="lineas">
                <div class="cesta__vacia" data-cesta-vacia>
                    <p>Tu cesta está vacía.</p>
                    <a class="boton" href="/catalogo">Ver el catálogo</a>
                </div>
                <ul class="cesta__lineas" data-cesta-lineas></ul>

                <form class="cesta__pedido" data-cesta-pedido novalidate>
                    <div class="campo">
                        <label for="cesta-direccion">Dirección de envío</label>
                        <textarea id="cesta-direccion" name="direccion_envio" rows="3" maxlength="400" autocomplete="street-address" aria-describedby="cesta-direccion-error"></textarea>
                        <p class="campo__error" id="cesta-direccion-error" data-error="direccion_envio"></p>
                    </div>
                    <div class="campo">
                        <label for="cesta-notas">Notas para el envío <span class="campo__opcional">(opcional)</span></label>
                        <textarea id="cesta-notas" name="notas" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="cesta__total">
                        <span>Total estimado</span>
                        <strong data-cesta-total>0,00 €</strong>
                    </div>
                    <p class="cesta__nota">El importe final lo calcula la tienda al hacer el pedido.</p>
                    <p class="cesta__mensaje" data-cesta-mensaje role="alert"></p>
                    <button class="boton boton--grande cesta__enviar" type="submit">Hacer pedido</button>
                </form>
            </div>

            <div class="cesta__vista" data-vista="entrar" hidden>
                <p class="cesta__texto">Entra con tu cuenta para terminar el pedido. Lo que has elegido sigue en la cesta.</p>
                <form class="cesta__entrar" data-cesta-entrar novalidate>
                    <div class="campo">
                        <label for="cesta-email">Email</label>
                        <input id="cesta-email" name="email" type="email" autocomplete="email" required aria-describedby="cesta-email-error">
                        <p class="campo__error" id="cesta-email-error" data-error="email"></p>
                    </div>
                    <div class="campo">
                        <label for="cesta-password">Contraseña</label>
                        <input id="cesta-password" name="password" type="password" autocomplete="current-password" required aria-describedby="cesta-password-error">
                        <p class="campo__error" id="cesta-password-error" data-error="password"></p>
                    </div>
                    <p class="cesta__mensaje" data-cesta-mensaje role="alert"></p>
                    <button class="boton boton--grande cesta__enviar" type="submit">Entrar y volver a la cesta</button>
                </form>
                <p class="cesta__texto">¿Aún no tienes cuenta? <a href="/registro?volver=/catalogo" data-cesta-registro>Crear cuenta</a></p>
                <button class="cesta__volver" type="button" data-cesta-ir="lineas">Volver a la cesta</button>
            </div>

            <div class="cesta__vista" data-vista="hecho" hidden>
                <p class="cesta__hecho">Pedido <strong data-pedido-codigo></strong></p>
                <p class="cesta__texto">Total <strong data-pedido-total></strong>. Lo preparamos en cuanto confirmemos el pago y te avisaremos cuando salga.</p>
                <button class="boton" type="button" data-cesta-cerrar>Seguir mirando</button>
            </div>
        </div>
    </dialog>
