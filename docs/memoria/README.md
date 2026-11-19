# Memoria técnica · fuente

El PDF de la memoria ([`../KALMA_SNKRS_Memoria_Tecnica.pdf`](../KALMA_SNKRS_Memoria_Tecnica.pdf)) se genera a partir
de estos archivos:

| Archivo | Contenido |
|---|---|
| `memoria.html` | Texto de la memoria |
| `memoria.css` | Maquetación A4: portada, índice, tablas, figuras y páginas apaisadas para los diagramas E/R |
| `capturas/` | Capturas de la aplicación, de las pruebas y del despliegue en el servidor común |
| `generar-pdf.mjs` | Imprime el HTML con Chrome, añade la cabecera y el pie y numera el índice |

Los diagramas E/R se toman de `../diagrama-er-conceptual.svg` y `../diagrama-er-relacional.svg`.

## Regenerar el PDF

Hace falta Node.js y Google Chrome (o Edge). Si Chrome no está en su ruta habitual, se indica en `CHROME_PATH`.

```bash
cd docs/memoria
npm install        # solo la primera vez (node_modules no se sube al repositorio)
npm run pdf
```

El script imprime la memoria dos veces: en la primera busca en qué página empieza cada apartado y en la segunda
escribe esos números en el índice.
