/*
 * Contador de la barra lateral: cuántos informes de stock esperan a que
 * administración los revise, junto a «Informes de stock».
 *
 * Solo para quien los revisa (informes_stock.revisar); al almacén no le dice
 * nada que tenga que hacer. Se pide al cargar el panel y otra vez cuando una
 * sección avisa de un cambio (un informe enviado o revisado).
 */

import { api, crear } from '../api.js';
import { contar } from './comun.js';

const raiz = document.getElementById('panel');
const enlace = raiz?.querySelector('[data-seccion="informes-stock"]');
const permisos = new Set(JSON.parse(raiz?.dataset.permisos || '[]'));

if (enlace && permisos.has('informes_stock.revisar')) {
    const insignia = crear('span', 'insignia num');
    // La cifra ya se lee en el nombre del enlace: la insignia es solo para la vista
    insignia.setAttribute('aria-hidden', 'true');
    enlace.append(insignia);
    const nombre = enlace.firstChild.textContent.trim();

    let control = null;
    const actualizar = async () => {
        control?.abort();
        control = new AbortController();
        try {
            const { paginacion } = await api('/api/gestion/informes-stock?estado=pendiente&por_pagina=1', { senal: control.signal });
            const pendientes = paginacion.total;
            insignia.textContent = pendientes > 0 ? String(pendientes) : '';
            enlace.setAttribute('aria-label', pendientes > 0 ? `${nombre}, ${contar(pendientes, 'pendiente', 'pendientes')}` : nombre);
        } catch {
            // Un fallo aquí no debe molestar: la sección enseña su propio error si lo hay
        }
    };
    actualizar();
    document.addEventListener('panel:cambio', actualizar);
}
