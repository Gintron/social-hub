#!/usr/bin/env node
/*
 * Snima sličice videa „izašao je novi katalog“: otvori paket scene (App\Catalog\CatalogSceneBundle) u Chromiumu,
 * za svaki trenutak t pozove window.renderAt(t) i snimi JPEG. Sličica koja je identična prethodnoj (isti ključ
 * stanja) se ne snima ponovno nego se veže na već snimljenu: završna kartica od 4 s košta jednu sličicu.
 *
 *   node catalog-frames.mjs <paket> --out <mapa> [--quality 94]        sve sličice: <mapa>/00000.jpg …
 *   node catalog-frames.mjs <paket> --out <mapa> --at 1.4,3.1          samo te trenutke, kao PNG: <mapa>/still-1.40.png
 *
 * Ispisuje jedan JSON redak: { frames, unique, seconds, duration }.
 */
import { copyFileSync, linkSync, mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import puppeteer from 'puppeteer';

const args = process.argv.slice(2);
const bundle = resolve(args[0] ?? '');
const option = (name, fallback = null) => {
  const index = args.indexOf(`--${name}`);
  return index >= 0 ? args[index + 1] : fallback;
};

const out = resolve(option('out', join(bundle, 'frames')));
const quality = Number(option('quality', 94));
const stills = option('at') ? option('at').split(',').map(Number) : null;
mkdirSync(out, { recursive: true });

const started = Date.now();
const browser = await puppeteer.launch({
  executablePath: process.env.HUB_CHROME_PATH || undefined,
  headless: 'new',
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none', '--allow-file-access-from-files'],
});

try {
  const page = await browser.newPage();
  await page.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 1 });

  const failures = [];
  page.on('pageerror', (error) => failures.push(String(error)));
  page.on('requestfailed', (request) => failures.push(`zahtjev nije uspio: ${request.url()}`));

  await page.goto(pathToFileURL(join(bundle, 'index.html')).href, { waitUntil: 'load' });
  await page.evaluate(() => window.sceneReady);

  if (failures.length > 0) {
    throw new Error(`Scena se nije učitala: ${failures.slice(0, 3).join('; ')}`);
  }

  const { fps, duration } = await page.evaluate(() => ({ fps: window.SCENE.fps, duration: window.SCENE.timeline.duration }));

  if (stills) {
    for (const t of stills) {
      await page.evaluate((time) => window.renderAt(time), t);
      await page.screenshot({ path: join(out, `still-${t.toFixed(2)}.png`), type: 'png' });
    }
    console.log(JSON.stringify({ stills: stills.length, seconds: (Date.now() - started) / 1000, duration }));
  } else {
    const count = Math.ceil(duration * fps - 1e-9);
    const name = (i) => join(out, `${String(i).padStart(5, '0')}.jpg`);
    let previousKey = null;
    let previousFile = null;
    let unique = 0;

    for (let i = 0; i < count; i++) {
      const key = await page.evaluate((time) => window.renderAt(time), i / fps);
      const file = name(i);

      if (key === previousKey && previousFile !== null) {
        try { linkSync(previousFile, file); } catch { copyFileSync(previousFile, file); }
      } else {
        await page.screenshot({ path: file, type: 'jpeg', quality });
        unique++;
        previousKey = key;
        previousFile = file;
      }
    }

    console.log(JSON.stringify({ frames: count, unique, seconds: (Date.now() - started) / 1000, duration }));
  }

  if (failures.length > 0) {
    throw new Error(`Greška u sceni: ${failures.slice(0, 3).join('; ')}`);
  }
} finally {
  await browser.close();
}
