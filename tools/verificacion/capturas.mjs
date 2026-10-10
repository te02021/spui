// Recorrido visual del CMS de SPUI con un navegador real (Chromium headless).
//
// Abre cada listado del panel y cada tipo de modal (Nuevo, Ver, Editar, Ítems,
// Energía…) en escritorio (1366 px) y celular (390 px), guarda una captura de
// cada estado y FALLA ante lo que una persona vería mal y un lint no detecta:
//   - errores de JavaScript (consola o excepción no atrapada);
//   - pedidos del propio sitio que responden 4xx/5xx;
//   - elementos con el atributo `hidden` que igual se ven (el caso real de
//     Bootstrap `.d-flex` pisando `[hidden]`, docs/README.md §3.4);
//   - imágenes rotas; textos "undefined", "NaN", "[object Object]" o mensajes
//     de validación en inglés;
//   - en "Nuevo" → Guardar vacío: que aparezcan los errores debajo de los
//     campos (y que NO se haya creado nada).
// Avisa (sin fallar) del scroll horizontal en celular y de un broker MQTT por
// WebSocket que no conecta.
//
// Las capturas quedan en tools/verificacion/capturas/ (gitignorado) para
// revisarlas a ojo antes de entregar un cambio.
//
// Uso:
//   node tools/verificacion/capturas.mjs [--base=http://intranet] [--solo=pantallas,alertas]
//                                        [--sesion=<PHPSESSID>] [--sin-modales]
// Playwright se resuelve desde SPUI_PLAYWRIGHT_DIR (carpeta con node_modules/playwright)
// y Chromium desde PLAYWRIGHT_BROWSERS_PATH; verificar.ps1 setea las dos.

import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const args = Object.fromEntries(process.argv.slice(2).map((a) => {
    const [k, ...v] = a.replace(/^--/, '').split('=');
    return [k, v.length ? v.join('=') : true];
}));

const BASE = String(args.base || 'http://intranet').replace(/\/$/, '');
const SOLO = args.solo ? String(args.solo).split(',') : null;
const SALIDA = path.join(AQUI, 'capturas');

const requerir = createRequire(path.join(process.env.SPUI_PLAYWRIGHT_DIR || AQUI, 'package.json'));
let chromium;
try {
    ({ chromium } = requerir('playwright'));
} catch {
    console.error('No encuentro Playwright. Instalarlo en una carpeta y apuntar SPUI_PLAYWRIGHT_DIR a ella:\n'
        + '  npm i playwright@1 && npx playwright install chromium-headless-shell');
    process.exit(2);
}

// ── Qué se recorre ──────────────────────────────────────────────────────────
const PANTALLAS = [
    ['dashboard', '/spui'],
    ['edificios', '/spui/edificios'],
    ['ubicaciones', '/spui/ubicaciones'],
    ['pantallas', '/spui/pantallas'],
    ['reproductores', '/spui/reproductores'],
    ['contenidos', '/spui/contenidos'],
    ['playlists', '/spui/playlists'],
    ['programacion', '/spui/programacion'],
    ['alertas', '/spui/alertas'],
];
const VISTAS = [
    { nombre: 'escritorio', viewport: { width: 1366, height: 768 } },
    { nombre: 'celular', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
];

// ── Resultados ──────────────────────────────────────────────────────────────
const fallas = [];
const avisos = [];
const capturas = [];
let contexto = '';
const falla = (m) => { fallas.push(`[${contexto}] ${m}`); console.log(`  FALLA ${m}`); };
const aviso = (m) => { avisos.push(`[${contexto}] ${m}`); console.log(`  AVISO ${m}`); };

function sesion() {
    if (args.sesion) return String(args.sesion);
    const salida = execFileSync('php', [path.join(AQUI, 'sesion.php')], { encoding: 'utf8' }).trim().split(/\r?\n/);
    return salida[salida.length - 1];
}

/** Revisión del DOM visible: lo que se ve roto aunque no haya ningún error de JS. */
async function revisarDom(page, alcance, esCelular) {
    const r = await page.evaluate(({ alcance }) => {
        const raiz = alcance ? document.querySelector(alcance) : document.body;
        if (!raiz) return { sinRaiz: true };
        const visible = (e) => getComputedStyle(e).display !== 'none' && getComputedStyle(e).visibility !== 'hidden' && e.getClientRects().length > 0;
        const describir = (e) => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 3).join('.') : '');

        const hiddenVisibles = [...raiz.querySelectorAll('[hidden]')].filter(visible).map(describir);
        const imgsRotas = [...raiz.querySelectorAll('img')].filter((i) => visible(i) && i.complete && i.naturalWidth === 0).map((i) => i.getAttribute('src'));
        const texto = raiz.innerText || '';
        const basura = ['undefined', 'NaN', '[object Object]', 'This value should', 'This value is not'].filter((t) => texto.includes(t));

        let desborde = null;
        if (!alcance && document.documentElement.scrollWidth > window.innerWidth + 1) {
            const culpables = [...document.querySelectorAll('body *')]
                .filter((e) => visible(e) && e.getBoundingClientRect().right > window.innerWidth + 1)
                .filter((e) => ![...e.children].some((h) => h.getBoundingClientRect().right > window.innerWidth + 1))
                .slice(0, 3).map(describir);
            desborde = { ancho: document.documentElement.scrollWidth, ventana: window.innerWidth, culpables };
        }
        return { hiddenVisibles, imgsRotas, basura, desborde };
    }, { alcance });

    if (r.sinRaiz) { falla(`no aparece ${alcance}`); return; }
    for (const h of r.hiddenVisibles) falla(`elemento con [hidden] que igual se ve: ${h}`);
    for (const i of r.imgsRotas) falla(`imagen rota: ${i}`);
    for (const b of r.basura) falla(`texto sospechoso en pantalla: "${b}"`);
    if (r.desborde && esCelular) aviso(`scroll horizontal: la página mide ${r.desborde.ancho}px en una ventana de ${r.desborde.ventana}px (${r.desborde.culpables.join(', ')})`);
}

async function capturar(page, vista, nombre, alcance) {
    const archivo = path.join(SALIDA, vista, `${String(capturas.length + 1).padStart(3, '0')}-${nombre}.png`);
    if (!alcance) {
        await page.screenshot({ path: archivo, fullPage: true, animations: 'disabled' });
        capturas.push(path.relative(AQUI, archivo));
        return;
    }
    // Un modal con scroll interno sólo mostraría la parte visible: se agranda
    // la ventana a la altura real de su contenido, se captura y se restaura.
    const original = page.viewportSize();
    const extra = await page.evaluate((sel) => {
        const raiz = document.querySelector(sel);
        let max = 0;
        for (const e of [raiz, ...raiz.querySelectorAll('*')]) max = Math.max(max, e.scrollHeight - e.clientHeight);
        return Math.max(0, Math.ceil(raiz.getBoundingClientRect().height + max + 120 - window.innerHeight));
    }, alcance);
    if (extra > 0) { await page.setViewportSize({ width: original.width, height: original.height + extra }); await page.waitForTimeout(250); }
    await page.locator(alcance).screenshot({ path: archivo, animations: 'disabled' });
    if (extra > 0) await page.setViewportSize(original);
    capturas.push(path.relative(AQUI, archivo));
}

const overlayAbierto = (page) => page.locator('#spuiAbmOverlay.is-open');

async function esperarModal(page) {
    await overlayAbierto(page).waitFor({ state: 'visible', timeout: 10000 });
    // modal.js pone un spinner mientras trae el HTML del modal.
    await page.waitForFunction(() => {
        const b = document.getElementById('spuiAbmBody');
        return b && b.children.length > 0 && !b.querySelector('.spinner-border');
    }, null, { timeout: 15000 });
    await page.waitForTimeout(400); // transición de apertura
}

async function cerrarModales(page) {
    for (let i = 0; i < 3 && await overlayAbierto(page).count(); i++) {
        await page.keyboard.press('Escape');
        if (await overlayAbierto(page).count()) await page.locator('#spuiAbmClose').click({ timeout: 2000 }).catch(() => {});
        await page.waitForTimeout(350);
    }
}

/** Un botón representativo por tipo de modal (crear, ver, editar, ítems…). */
async function botonesDeModal(page) {
    return page.evaluate(() => {
        const vistos = new Set();
        const out = [];
        document.querySelectorAll('[data-spui-form]').forEach((b, i) => {
            if (!b.offsetParent || b.hasAttribute('data-spui-bloqueado') || b.classList.contains('disabled')) return;
            const url = b.getAttribute('data-spui-form');
            // tipo = la acción final de la URL (ver, editar, nuevo, items, energia…) sin ids
            const tipo = url.replace(/\/\d+/g, '').replace(/\/$/, '').split('/').pop();
            if (vistos.has(tipo)) return;
            vistos.add(tipo);
            b.setAttribute('data-spui-verif', String(i));
            out.push({ tipo, sel: `[data-spui-verif="${i}"]`, crear: b.classList.contains('spui-btn-crear') });
        });
        return out;
    });
}

/** Nuevo → Guardar sin completar nada: tienen que verse los errores y no crearse nada. */
async function guardarVacio(page, vista, nombre) {
    const form = page.locator('#spuiAbmBody form[data-spui-ajax]');
    if (!await form.count()) return;
    await page.evaluate(() => {
        const f = document.querySelector('#spuiAbmBody form[data-spui-ajax]');
        f.querySelectorAll('input[type=text],input[type=number],input[type=time],input[type=date],input[type=datetime-local],input[type=url],input:not([type]),textarea')
            .forEach((i) => { i.value = ''; });
        f.querySelectorAll('select').forEach((s) => { if ([...s.options].some((o) => o.value === '')) s.value = ''; });
    });

    let creado = null;
    const alResponder = async (resp) => {
        if (resp.request().method() !== 'POST') return;
        try { const j = await resp.json(); if (j && j.success === true) creado = resp.url(); } catch { /* no era JSON */ }
    };
    page.on('response', alResponder);
    await form.locator('[type=submit]').first().click();
    await page.waitForTimeout(1500);
    page.off('response', alResponder);

    if (creado) falla(`Guardar con el formulario vacío CREÓ un registro (${creado}) — borrarlo a mano y revisar las validaciones`);
    const errores = await page.locator('#spuiAbmBody .spui-campo-error:visible, #spuiAbmBody .is-invalid:visible, #spuiAbmBody .invalid-feedback:visible').count();
    if (!creado && errores === 0) falla('Guardar vacío no muestra ningún error debajo de los campos');
    await capturar(page, vista, `${nombre}-guardar-vacio`, '#spuiAbmModal');
}

// ── Recorrido ───────────────────────────────────────────────────────────────
rmSync(SALIDA, { recursive: true, force: true });
const id = sesion();
const navegador = await chromium.launch();

for (const v of VISTAS) {
    mkdirSync(path.join(SALIDA, v.nombre), { recursive: true });
    const ctx = await navegador.newContext({ viewport: v.viewport, isMobile: v.isMobile, hasTouch: v.hasTouch,
        deviceScaleFactor: v.deviceScaleFactor || 1, locale: 'es-AR', timezoneId: 'America/Argentina/Buenos_Aires' });
    await ctx.addCookies([{ name: 'PHPSESSID', value: id, domain: new URL(BASE).hostname, path: '/' }]);
    // La barra del profiler de Symfony (sólo dev) queda fija en medio de las
    // capturas de página completa y tapa contenido: no es parte del CMS.
    await ctx.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const s = document.createElement('style');
            s.textContent = '.sf-toolbar, .sf-minitoolbar { display: none !important; }';
            document.head.appendChild(s);
        });
    });
    const page = await ctx.newPage();
    const origen = new URL(BASE).origin;

    page.on('pageerror', (e) => falla(`excepción de JavaScript: ${e.message}`));
    page.on('console', (m) => {
        if (m.type() !== 'error') return;
        const t = m.text();
        if (/WebSocket|:9001|mqtt/i.test(t)) aviso(`WebSocket del broker: ${t.slice(0, 160)}`);
        else if (!/Failed to load resource/.test(t)) falla(`error de consola: ${t.slice(0, 200)}`);
    });
    page.on('response', (r) => {
        if (r.status() >= 400 && r.url().startsWith(origen)) falla(`HTTP ${r.status()} en ${r.url().slice(origen.length)}`);
    });
    page.on('requestfailed', (r) => {
        const err = r.failure()?.errorText || '';
        if (!/ERR_ABORTED/.test(err) && !r.url().includes(':9001')) aviso(`pedido fallido ${r.url()} (${err})`);
    });

    for (const [nombre, ruta] of PANTALLAS) {
        if (SOLO && !SOLO.includes(nombre)) continue;
        contexto = `${v.nombre} ${nombre}`;
        console.log(`\n${contexto}`);
        const resp = await page.goto(BASE + ruta, { waitUntil: 'load' });
        if (resp && resp.url().includes('unraf.edu.ar')) { falla('redirigió al login: la sesión de prueba no sirvió'); break; }
        await page.waitForTimeout(800);
        await revisarDom(page, null, !!v.isMobile);
        await capturar(page, v.nombre, nombre);

        if (args['sin-modales']) continue;
        for (const b of await botonesDeModal(page)) {
            contexto = `${v.nombre} ${nombre} › ${b.tipo}`;
            console.log(`  ${b.tipo}`);
            try {
                await page.locator(b.sel).scrollIntoViewIfNeeded();
                await page.locator(b.sel).click();
                await esperarModal(page);
                await revisarDom(page, '#spuiAbmModal', !!v.isMobile);
                await capturar(page, v.nombre, `${nombre}-${b.tipo}`, '#spuiAbmModal');
                if (b.crear) await guardarVacio(page, v.nombre, `${nombre}-${b.tipo}`);
            } catch (e) {
                falla(`no se pudo abrir o revisar el modal: ${e.message.split('\n')[0]}`);
                await capturar(page, v.nombre, `${nombre}-${b.tipo}-ERROR`).catch(() => {});
            }
            await cerrarModales(page);
        }
    }
    await ctx.close();
}
await navegador.close();

writeFileSync(path.join(SALIDA, 'resumen.json'), JSON.stringify({ fallas, avisos, capturas }, null, 2));
console.log(`\nCapturas: ${capturas.length} imágenes en ${path.relative(process.cwd(), SALIDA)}, ${fallas.length} fallas, ${avisos.length} avisos.`);
for (const f of fallas) console.log(`  ✗ ${f}`);
process.exit(fallas.length ? 1 : 0);
