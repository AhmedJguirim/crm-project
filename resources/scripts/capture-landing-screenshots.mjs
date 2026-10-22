/*
 * Retakes the landing page screenshots and the Open Graph image from the seeded demo organization.
 * Uses the testing database (srw-crm-test), never the dev database.
 *   php artisan migrate:fresh --seed --env=testing --no-interaction
 *   npm run build        # unless `composer run dev` is running (public/hot exists)
 *   CHROME_BIN=/path/to/chrome node resources/scripts/capture-landing-screenshots.mjs
 * Writes public/images/landing/{board,deal}-{720,1440}.webp, dashboard-{1440,2880}.webp and og.png.
 */
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const OUT = join(ROOT, 'public', 'images', 'landing');
const BASE = 'http://127.0.0.1:8010';
const SERVER_PORT = 8010;
const CHROME_PORT = 9339;
const QUALITY = 82;
const DASHBOARD_VIEWPORT_WIDTH = 1760;
const POLISH_CSS = '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const fail = (message) => {
    console.error(message);
    process.exit(1);
};

const answers = async (url) => {
    try {
        await fetch(url, { signal: AbortSignal.timeout(1000) });

        return true;
    } catch {
        return false;
    }
};

if (!process.env.CHROME_BIN) {
    fail('Set CHROME_BIN to a Chrome or Chromium binary.');
}

mkdirSync(OUT, { recursive: true });

const PHP = `
$user = App\\Models\\User::where("email", "test@example.com")->firstOrFail();
$org = $user->personalOrganization();
$deal = App\\Models\\Deal::forOrganization($org->id)->where("title", "Cloudbase Cost Optimization Retainer")->firstOrFail();
echo json_encode([
    "database" => config("database.connections.".config("database.default").".database"),
    "board" => App\\Filament\\Resources\\Deals\\DealResource::getUrl("pipeline", isAbsolute: false, panel: "admin", tenant: $org),
    "deal" => App\\Filament\\Resources\\Deals\\DealResource::getUrl("view", ["record" => $deal], isAbsolute: false, panel: "admin", tenant: $org),
    "dashboard" => App\\Filament\\Pages\\Dashboard::getUrl(isAbsolute: false, panel: "admin", tenant: $org),
]);
`;

const tinker = execFileSync('php', ['artisan', 'tinker', '--env=testing', '--no-interaction', '--execute', PHP], { cwd: ROOT, encoding: 'utf8' });
const paths = JSON.parse(tinker.trim().split('\n').at(-1));

if (paths.database !== 'srw-crm-test') {
    fail(`Refusing to capture: the testing database is "${paths.database}", expected "srw-crm-test".`);
}

for (const port of [SERVER_PORT, CHROME_PORT]) {
    if (await answers(`http://127.0.0.1:${port}/`)) {
        fail(`Port ${port} is busy; stop whatever uses it first.`);
    }
}

let server = null;
let chrome = null;
let ws = null;
let profile = null;

const cleanup = () => {
    try {
        ws?.close();
    } catch {}
    chrome?.kill('SIGKILL');
    server?.kill('SIGKILL');

    if (profile) {
        rmSync(profile, { recursive: true, force: true });
    }
};

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        cleanup();
        process.exit(1);
    });
}

try {
    server = spawn('php', ['-S', `127.0.0.1:${SERVER_PORT}`, join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
        cwd: join(ROOT, 'public'),
        env: { ...process.env, APP_ENV: 'testing', APP_URL: BASE, SESSION_DRIVER: 'cookie', DEBUGBAR_ENABLED: 'false', PHP_CLI_SERVER_WORKERS: '4' },
        stdio: 'ignore',
    });

    const serverDeadline = Date.now() + 15000;

    while (!(await answers(`${BASE}/up`))) {
        if (Date.now() > serverDeadline) {
            throw new Error('The PHP server did not start.');
        }

        await sleep(100);
    }

    profile = mkdtempSync(join(tmpdir(), 'crm-shots-'));
    chrome = spawn(process.env.CHROME_BIN, [
        '--headless',
        `--remote-debugging-port=${CHROME_PORT}`,
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--hide-scrollbars',
        '--force-color-profile=srgb',
        '--lang=en-US',
        'about:blank',
    ], { stdio: 'ignore' });

    let target = null;
    const chromeDeadline = Date.now() + 15000;

    while (!target) {
        if (Date.now() > chromeDeadline) {
            throw new Error('Chrome did not start.');
        }

        try {
            const list = await (await fetch(`http://127.0.0.1:${CHROME_PORT}/json/list`)).json();
            target = list.find((entry) => entry.type === 'page') ?? null;
        } catch {}

        if (!target) {
            await sleep(100);
        }
    }

    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
    });

    let id = 0;
    const pending = new Map();
    const listeners = new Set();
    ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } else if (m.method) { listeners.forEach((l) => l(m)); } });
    const send = (method, params = {}) => new Promise((resolve, reject) => { const i = ++id; pending.set(i, (m) => (m.error ? reject(new Error(`${method}: ${m.error.message}`)) : resolve(m.result))); ws.send(JSON.stringify({ id: i, method, params })); });

    await send('Page.enable');
    await send('Network.enable');
    await send('Runtime.enable');

    const inFlight = new Set();
    let lastActivity = Date.now();

    listeners.add((m) => {
        if (m.method === 'Network.requestWillBeSent') {
            inFlight.add(m.params.requestId);
            lastActivity = Date.now();
        } else if (m.method === 'Network.loadingFinished' || m.method === 'Network.loadingFailed') {
            inFlight.delete(m.params.requestId);
            lastActivity = Date.now();
        }
    });

    const evaluate = (expression, awaitPromise = false) => send('Runtime.evaluate', { expression, awaitPromise, returnByValue: true });

    const waitForIdle = async (url) => {
        const deadline = Date.now() + 20000;

        while (inFlight.size > 0 || Date.now() - lastActivity < 1000) {
            if (Date.now() > deadline) {
                throw new Error(`Timed out waiting for ${url} to settle.`);
            }

            await sleep(100);
        }
    };

    const goto = async (url) => {
        const loaded = new Promise((resolve) => {
            const listener = (m) => {
                if (m.method === 'Page.loadEventFired') {
                    listeners.delete(listener);
                    resolve();
                }
            };

            listeners.add(listener);
        });

        await send('Page.navigate', { url });
        await loaded;
        await waitForIdle(url);
        await evaluate('document.fonts.ready', true);
        await evaluate(`(() => { const style = document.createElement('style'); style.textContent = ${JSON.stringify(POLISH_CSS)}; document.head.appendChild(style); })()`);
        await sleep(1500);
    };

    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }, { name: 'prefers-reduced-motion', value: 'reduce' }] });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 2, mobile: false });

    await goto(`${BASE}/admin/login`);
    await evaluate("localStorage.setItem('theme', 'light')");
    await evaluate(`(() => {
        const fill = (selector, value) => { const input = document.querySelector(selector); input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); };
        fill('input[type=email]', 'test@example.com');
        fill('input[type=password]', 'password');
        document.querySelector('form button[type=submit]').click();
    })()`);

    const loginDeadline = Date.now() + 20000;

    while ((await evaluate('location.pathname')).result.value.startsWith('/admin/login')) {
        if (Date.now() > loginDeadline) {
            throw new Error('Login failed: still on the login page.');
        }

        await sleep(200);
    }

    const written = [];

    const capture = async (file, params) => {
        const { data } = await send('Page.captureScreenshot', params);
        writeFileSync(join(OUT, file), Buffer.from(data, 'base64'));
        written.push(file);
    };

    for (const [name, path, widths, viewportWidth] of [['board', paths.board, [720, 1440], 1440], ['deal', paths.deal, [720, 1440], 1440], ['dashboard', paths.dashboard, [1440, 2880], DASHBOARD_VIEWPORT_WIDTH]]) {
        const viewportHeight = viewportWidth * 0.625;

        await send('Emulation.setDeviceMetricsOverride', { width: viewportWidth, height: viewportHeight, deviceScaleFactor: 2, mobile: false });
        await goto(BASE + path);

        for (const width of widths) {
            await capture(`${name}-${width}.webp`, { format: 'webp', quality: QUALITY, clip: { x: 0, y: 0, width: viewportWidth, height: viewportHeight, scale: width / (viewportWidth * 2) } });
        }
    }

    await send('Network.clearBrowserCookies');
    await send('Emulation.setDeviceMetricsOverride', { width: 1600, height: 840, deviceScaleFactor: 1, mobile: false });
    await goto(`${BASE}/`);
    await capture('og.png', { format: 'png', clip: { x: 0, y: 0, width: 1600, height: 840, scale: 0.75 } });

    for (const file of written) {
        const dimensions = file === 'og.png' ? '1200x630' : file.endsWith('2880.webp') ? '2880x1800' : file.endsWith('1440.webp') ? '1440x900' : '720x450';
        console.log(`public/images/landing/${file}  ${dimensions}  ${Math.round(statSync(join(OUT, file)).size / 1024)} KB`);
    }
} finally {
    cleanup();
}
