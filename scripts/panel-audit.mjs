#!/usr/bin/env node
/**
 * Opens every page of the panel the way a person with a laptop, a phone and a dark theme would, and
 * reports what is wrong with how it looks: the page scrolling sideways, sections of unequal height side
 * by side (the layout this panel was reported for), errors in the console, and WCAG A/AA failures (axe).
 *
 * It finds the pages itself: the menu, then the first record and the "new" page of each list, so a new
 * resource is looked at without being added anywhere. Run it after a change to how the panel looks:
 *
 *   AUDIT_EMAIL=you@example.com AUDIT_PASSWORD=secret node scripts/panel-audit.mjs
 *   node scripts/panel-audit.mjs --base=http://localhost:8100 --widths=1440,390 --shots=storage/audit
 *
 * The user must be in HUB_ADMIN_EMAILS, and the database should hold some of everything (a brand, a
 * source, an account, an item, a draft), or the pages that need one are skipped. Chrome comes from
 * HUB_CHROME_PATH, or from Puppeteer's cache. Exit code 1 when anything is wrong.
 *
 * Flags: --base, --email, --password, --widths=1440,390, --schemes=light,dark, --shots=dir,
 * --only=/admin/brands (just the pages whose path contains this: a quick look while you work).
 */
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import puppeteer from 'puppeteer';

const require = createRequire(import.meta.url);
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const args = Object.fromEntries(
    process.argv.slice(2).map((arg) => {
        const [key, value = 'true'] = arg.replace(/^--/, '').split('=');

        return [key, value];
    }),
);

const BASE = (args.base || process.env.AUDIT_URL || 'http://localhost:8100').replace(/\/$/, '');
const EMAIL = args.email || process.env.AUDIT_EMAIL;
const PASSWORD = args.password || process.env.AUDIT_PASSWORD;
const WIDTHS = (args.widths || '1440,390').split(',').map(Number);
const SCHEMES = (args.schemes || 'light,dark').split(',');
const SHOTS = args.shots || null;
const ONLY = args.only || null;
const SETTLE_MS = Number(process.env.AUDIT_SETTLE_MS || 1500);

// Gaps of this many pixels between the bottoms of two sections side by side are what "big gaps" looks like.
const UNEVEN_PX = 60;

if (!EMAIL || !PASSWORD) {
    console.error('Set AUDIT_EMAIL and AUDIT_PASSWORD (or --email and --password): a user listed in HUB_ADMIN_EMAILS.');
    process.exit(2);
}

if (SHOTS) mkdirSync(SHOTS, { recursive: true });

/** `/admin/brands/12/edit` and `/admin/brands/7/edit` are the same page for our purposes. */
const shape = (pathname) => pathname.replace(/\/\d+(?=\/|$)/g, '/{id}');

async function login(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' });

    // Every page of this browser shares one session: after the first login the form is not there.
    if (!(await page.$('input[type=email]'))) return;

    await page.type('input[type=email]', EMAIL);
    await page.type('input[type=password]', PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);

    if (new URL(page.url()).pathname.includes('/login')) {
        throw new Error('Login did not work: check the e-mail and password, and HUB_ADMIN_EMAILS.');
    }
}

/** Livewire loads the parts of a page below the fold when they come into view; scroll so they do. */
async function loadEverything(page) {
    await page.evaluate(async () => {
        for (let y = 0; y < document.body.scrollHeight; y += 500) {
            window.scrollTo(0, y);
            await new Promise((resolve) => setTimeout(resolve, 120));
        }
        window.scrollTo(0, 0);
    });
    await new Promise((resolve) => setTimeout(resolve, SETTLE_MS));
}

/** Pages to look at, as paths, found by following the menu and one level into each list. */
async function discover(page) {
    const found = new Map();
    const add = (href) => {
        const url = new URL(href, BASE);

        if (url.origin !== new URL(BASE).origin || !url.pathname.startsWith('/admin') || /logout|login/.test(url.pathname)) return;
        if (!found.has(shape(url.pathname))) found.set(shape(url.pathname), url.pathname);
    };

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle0' });
    add('/admin');
    for (const href of await page.$$eval('.fi-sidebar-item a[href]', (links) => links.map((link) => link.href))) add(href);

    for (const path of [...found.values()]) {
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle0' });
        await loadEverything(page);

        const next = await page.evaluate(() => [
            ...document.querySelectorAll('a[href$="/create"]'),
            ...[...document.querySelectorAll('.fi-ta-row')].slice(0, 1).flatMap((row) => [...row.querySelectorAll('a[href]')].slice(0, 1)),
        ].map((link) => link.href));

        next.forEach(add);
    }

    return [...found.values()];
}

/** What is wrong with the page as it stands now. */
async function measure(page) {
    const layout = await page.evaluate((unevenPx) => {
        const root = document.documentElement;
        const sections = [...document.querySelectorAll('.fi-section')].filter((el) => el.offsetParent !== null).map((el) => el.getBoundingClientRect());
        const uneven = [];

        for (let i = 0; i < sections.length; i++) {
            for (let j = i + 1; j < sections.length; j++) {
                const a = sections[i];
                const b = sections[j];
                const sideBySide = a.right <= b.left + 2 || b.right <= a.left + 2;
                const overlap = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);

                if (sideBySide && overlap > 60 && Math.abs(a.bottom - b.bottom) > unevenPx) uneven.push(Math.round(Math.abs(a.bottom - b.bottom)));
            }
        }

        return {
            title: (document.querySelector('h1')?.textContent || '').trim().slice(0, 40),
            overflow: Math.max(0, root.scrollWidth - window.innerWidth),
            uneven,
        };
    }, UNEVEN_PX);

    await page.addScriptTag({ content: axeSource });
    const axe = await page.evaluate(async () => {
        const result = await axe.run(document, { resultTypes: ['violations'], runOnly: ['wcag2a', 'wcag2aa'] });

        return result.violations
            .filter((violation) => ['serious', 'critical'].includes(violation.impact))
            .map((violation) => `${violation.id} (${violation.nodes.length}): ${violation.nodes[0].target.join(' ').slice(0, 70)}`);
    });

    return { ...layout, axe };
}

const browser = await puppeteer.launch({
    executablePath: process.env.HUB_CHROME_PATH || puppeteer.executablePath(),
    args: ['--no-sandbox'],
});

const failures = [];
let looked = 0;

try {
    const discoverer = await browser.newPage();
    await login(discoverer);
    const paths = (await discover(discoverer)).filter((path) => !ONLY || path.includes(ONLY));
    await discoverer.close();

    console.log(`Looking at ${paths.length} pages: ${paths.join(', ')}\n`);

    for (const scheme of SCHEMES) {
        for (const width of WIDTHS) {
            const page = await browser.newPage();
            await page.setViewport({ width, height: 900 });
            await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);

            const errors = [];
            page.on('pageerror', (error) => errors.push(`script error: ${String(error).slice(0, 120)}`));
            page.on('console', (message) => {
                // An image that does not load is the data's fault, not the panel's.
                if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(`console: ${message.text().slice(0, 120)}`);
            });
            page.on('response', (response) => {
                if (response.status() >= 400 && response.url().startsWith(BASE) && !/favicon/.test(response.url())) errors.push(`HTTP ${response.status()}: ${response.url().replace(BASE, '')}`);
            });

            await login(page);

            for (const path of paths) {
                errors.length = 0;
                await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle0' });
                await loadEverything(page);

                // A page with tabs is several pages: look at each.
                const tabCount = (await page.$$('[role=tab]')).length;

                for (let tab = -1; tab < tabCount; tab++) {
                    if (tab >= 0) {
                        await (await page.$$('[role=tab]'))[tab].click();
                        await new Promise((resolve) => setTimeout(resolve, 400));
                    }

                    const result = await measure(page);
                    const problems = [
                        result.overflow > 1 ? `page scrolls sideways by ${result.overflow}px` : null,
                        result.uneven.length ? `sections side by side differ by ${result.uneven.join(', ')}px` : null,
                        ...result.axe,
                        ...errors,
                    ].filter(Boolean);

                    looked++;

                    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${scheme}-${width}-${shape(path).replace(/\W+/g, '_')}${tab >= 0 ? `-tab${tab}` : ''}.png`, fullPage: true });

                    if (problems.length) {
                        const where = `${scheme} ${width}px ${path}${tab >= 0 ? ` [tab ${tab + 1}]` : ''}`;
                        failures.push({ where, title: result.title, problems });
                    }
                }
            }

            await page.close();
        }
    }
} finally {
    await browser.close();
}

for (const failure of failures) {
    console.log(`✗ ${failure.where}  ${failure.title}`);
    failure.problems.forEach((problem) => console.log(`    ${problem}`));
}

console.log(`\n${looked} views looked at, ${failures.length} with problems.`);
process.exit(failures.length === 0 ? 0 : 1);
