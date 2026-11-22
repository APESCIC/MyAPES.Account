/**
 * Browser smoke for push subscribe/unsubscribe on Herd (#805).
 * Skips unless MYAPES_PUSH_SMOKE=1 and https://myapes-account.test answers.
 */
import process from 'node:process';

const baseUrl = process.env.MYAPES_PUSH_SMOKE_URL ?? 'https://myapes-account.test';
const loginEmail = process.env.MYAPES_PUSH_SMOKE_EMAIL ?? 'staff@example.test';
const loginPassword = process.env.MYAPES_PUSH_SMOKE_PASSWORD ?? 'Local-Demo-Only-2026!';

const skip = (reason) => {
    console.log(`push-smoke: skipped (${reason})`);
    process.exit(0);
};

if (process.env.MYAPES_PUSH_SMOKE !== '1') {
    skip('set MYAPES_PUSH_SMOKE=1 to run on Herd');
}

let healthOk = false;

try {
    const response = await fetch(`${baseUrl}/healthz`, { signal: AbortSignal.timeout(8000) });
    healthOk = response.ok;
} catch {
    healthOk = false;
}

if (!healthOk) {
    skip(`Herd site not reachable at ${baseUrl}`);
}

const vapidResponse = await fetch(`${baseUrl}/push/vapid-public-key`, {
    headers: { Accept: 'application/json' },
    signal: AbortSignal.timeout(8000),
});

if (!vapidResponse.ok) {
    skip('VAPID public key is not available (configure keys locally or via Admin)');
}

const vapidBody = await vapidResponse.json();

if (typeof vapidBody.publicKey !== 'string' || vapidBody.publicKey === '') {
    skip('VAPID public key missing from /push/vapid-public-key');
}

const { chromium } = await import('playwright');

console.log(`push-smoke: running against ${baseUrl}`);

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    permissions: ['notifications'],
});
const page = await context.newPage();

await page.addInitScript(() => {
    window.__pushPermissionRequests = 0;
    const requestPermission = window.Notification?.requestPermission?.bind(window.Notification);

    if (requestPermission) {
        window.Notification.requestPermission = (...args) => {
            window.__pushPermissionRequests += 1;

            return requestPermission(...args);
        };
    }
});

await page.goto(`${baseUrl}/staff/login`, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', loginEmail);
await page.fill('input[name="password"]', loginPassword);
await page.locator('button[type="submit"]').first().click();
await page.waitForURL((url) => !url.pathname.endsWith('/staff/login'), { timeout: 30_000 });

await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'networkidle' });
const requestsOnDashboard = await page.evaluate(() => window.__pushPermissionRequests ?? 0);

if (requestsOnDashboard !== 0) {
    await browser.close();
    console.error('push-smoke: Notification.requestPermission was called on dashboard load');
    process.exit(1);
}

await page.goto(`${baseUrl}/profile`, { waitUntil: 'networkidle' });
const turnOn = page.locator('[data-push-turn-on]').first();
await turnOn.waitFor({ state: 'visible', timeout: 15_000 });
await turnOn.click();

await page.waitForFunction(
    () => document.querySelector('[data-push-state="subscribed"]')?.hidden === false
        || document.querySelector('[data-push-turn-off]')?.hidden === false,
    null,
    { timeout: 30_000 },
);

const turnOff = page.locator('[data-push-turn-off]').first();
await turnOff.waitFor({ state: 'visible', timeout: 15_000 });
await turnOff.click();

await page.waitForFunction(
    () => document.querySelector('[data-push-turn-on]')?.hidden === false,
    null,
    { timeout: 30_000 },
);

await browser.close();
console.log('push-smoke: passed (no prompt on dashboard load; subscribe and unsubscribe on profile)');