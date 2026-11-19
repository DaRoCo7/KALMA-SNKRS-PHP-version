// Genera docs/KALMA_SNKRS_Memoria_Tecnica.pdf a partir de memoria.html con Chrome.
//
//   cd docs/memoria && npm install && npm run pdf
//
// Chrome imprime el HTML en A4 con la cabecera y el pie de cada página. Se imprime dos veces: en la primera se
// busca en qué página empieza cada apartado (leyendo el texto del PDF) y en la segunda esos números ya salen en
// el índice. Si Chrome no está en su ruta habitual, se indica con la variable CHROME_PATH.
import { existsSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import puppeteer from 'puppeteer-core';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { PDFDocument, rgb } from 'pdf-lib';

const aqui = dirname(fileURLToPath(import.meta.url));
const salida = join(aqui, '..', 'KALMA_SNKRS_Memoria_Tecnica.pdf');

const chrome = [
  process.env.CHROME_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].find((ruta) => ruta && existsSync(ruta));
if (!chrome) {
  console.error('No encuentro Chrome: indica su ruta en CHROME_PATH');
  process.exit(1);
}

// Cabecera y pie: Chrome los dibuja en el margen de cada página (sin hojas de estilo externas)
const estilo = 'font-family:Arial,Helvetica,sans-serif;font-size:7.5pt;width:100%;margin:0 17mm;'
  + '-webkit-print-color-adjust:exact;display:flex;justify-content:space-between;';
const cabecera = `<div style="${estilo}color:#16233A;border-bottom:0.6pt solid #DCE3EB;padding-bottom:2mm;">`
  + '<span><b>KALMA SNKRS</b> &nbsp;|&nbsp; <b>MEMORIA TÉCNICA</b></span><span style="color:#64748B">Sneaker Factory</span></div>';
const pie = `<div style="${estilo}color:#64748B;border-top:0.6pt solid #DCE3EB;padding-top:2mm;">`
  + '<span>Proyecto intermodular · 2.º DAW · XTART</span>'
  + '<span>Página <span class="pageNumber"></span> de <span class="totalPages"></span></span></div>';

async function imprimir(pagina) {
  return pagina.pdf({
    preferCSSPageSize: true,
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: cabecera,
    footerTemplate: pie,
    outline: true,
    tagged: true,
  });
}

/** Texto de cada página del PDF, sin espacios, para buscar los títulos */
async function textosPorPagina(pdf) {
  const doc = await getDocument({ data: new Uint8Array(pdf), verbosity: 0 }).promise;
  const textos = [];
  for (let i = 1; i <= doc.numPages; i++) {
    const contenido = await (await doc.getPage(i)).getTextContent();
    textos.push(contenido.items.map((x) => x.str).join('').replace(/\s+/g, ''));
  }
  return textos;
}

const navegador = await puppeteer.launch({ executablePath: chrome, headless: true });
try {
  const pagina = await navegador.newPage();
  await pagina.goto(pathToFileURL(join(aqui, 'memoria.html')).href, { waitUntil: 'networkidle0' });
  await pagina.evaluate(() => document.fonts.ready);

  // 1.ª pasada: en qué página empieza cada apartado (el primero que aparece después del índice)
  const textos = await textosPorPagina(await imprimir(pagina));
  const titulos = await pagina.evaluate(() => [...document.querySelectorAll('.pag[data-ref]')].map((s) => ({
    id: s.dataset.ref,
    titulo: document.getElementById(s.dataset.ref).querySelector('h2').textContent,
  })));
  const paginaIndice = textos.findIndex((t) => t.includes('Índice'));
  const numeros = {};
  for (const { id, titulo } of titulos) {
    const buscado = titulo.replace(/\s+/g, '').slice(0, 28);
    const i = textos.findIndex((t, n) => n > paginaIndice && t.includes(buscado));
    if (i < 0) throw new Error(`No encuentro el apartado «${titulo}» en el PDF`);
    numeros[id] = i + 1;
  }
  await pagina.evaluate((numeros) => {
    for (const s of document.querySelectorAll('.pag[data-ref]')) s.textContent = numeros[s.dataset.ref];
  }, numeros);

  // 2.ª pasada con el índice completo
  const pdf = await PDFDocument.load(await imprimir(pagina));

  // La portada no lleva cabecera ni pie: se tapan con el mismo negro del fondo
  const portada = pdf.getPage(0);
  const { width, height } = portada.getSize();
  const mm = 72 / 25.4;
  portada.drawRectangle({ x: 0, y: height - 22 * mm, width, height: 22 * mm, color: rgb(0, 0, 0) });
  portada.drawRectangle({ x: 0, y: 0, width, height: 19 * mm, color: rgb(0, 0, 0) });

  pdf.setTitle('Memoria técnica · KALMA SNKRS');
  pdf.setAuthor('David Rojas, David Moreno y Estefanía Correa');
  pdf.setSubject('Proyecto intermodular de 2.º DAW (XTART)');
  pdf.setKeywords(['KALMA SNKRS', 'Sneaker Factory', 'PHP', 'PostgreSQL', 'Docker']);
  pdf.setCreator('KALMA SNKRS');
  pdf.setProducer('KALMA SNKRS');
  pdf.setLanguage('es-ES');
  writeFileSync(salida, await pdf.save());
  console.log(`PDF generado: ${salida} (${pdf.getPageCount()} páginas)`);
  console.log('Índice:', numeros);
} finally {
  await navegador.close();
}
