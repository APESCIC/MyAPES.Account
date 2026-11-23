import { readFileSync } from 'node:fs';
import path from 'node:path';
import type { BrowserContext, Page } from '@playwright/test';

type MatrixPage = {
    key: string;
    path: string;
    auth: boolean;
    prepare?: string;
    expectStatus?: number;
};

export type ThemeSnapshotMatrix = {
    skins: string[];
    modes: string[];
    calm: string[];
    pages: MatrixPage[];
};

export const STAFF_EMAIL =
    process.env.THEME_E2E_EMAIL ?? 'theme-e2e@example.test';
export const STAFF_PASSWORD =
    process.env.THEME_E2E_PASSWORD ?? 'Local-Demo-Only-2026!';

export function loadMatrix(): ThemeSnapshotMatrix {
    const matrixPath = path.join(
        process.cwd(),
        'resources/data/theme-snapshot-matrix.json',
    );

    return JSON.parse(readFileSync(matrixPath, 'utf8')) as ThemeSnapshotMatrix;
}

export function snapshotName(
    pageKey: string,
    skin: string,
    mode: string,
    calm: string,
): string {
    return `${pageKey}-${skin}-${mode}-calm-${calm}.png`;
}

export async function assertAppReachable(baseURL: string): Promise<boolean> {
    try {
        const url = new URL('/healthz', baseURL);
        const response = await fetch(url, {
            signal: AbortSignal.timeout(10_000),
        });

        return response.ok;
    } catch {
        return false;
    }
}

export async function ensureLoggedIn(
    context: BrowserContext,
    page: Page,
    baseURL: string,
): Promise<void> {
    await context.clearCookies();

    await page.goto('/staff/login', { waitUntil: 'domcontentloaded' });

    const emailField = page.locator('input[name="email"]');
    await emailField.waitFor({ state: 'visible', timeout: 15_000 });
    await emailField.fill(STAFF_EMAIL);
    await page.locator('input[name="password"]').fill(STAFF_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL((url) => !url.pathname.endsWith('/staff/login'), {
        timeout: 30_000,
    });
    await page.waitForLoadState('domcontentloaded');
    await page.waitForLoadState('networkidle').catch(() => undefined);
}

function baseURLFromPage(page: Page): string {
    const current = page.url();

    return current !== 'about:blank'
        ? new URL(current).origin
        : (process.env.APP_URL ??
              process.env.BASE_URL ??
              'https://myapes-account.test');
}

async function readCsrfToken(context: BrowserContext): Promise<string> {
    const cookies = await context.cookies();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

    return token ? decodeURIComponent(token.value) : '';
}

export async function applyAppearance(
    context: BrowserContext,
    page: Page,
    skin: string,
    mode: string,
    calm: boolean,
): Promise<void> {
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
    const switcher = page.locator('[data-theme-switcher]').first();
    await switcher.waitFor({ state: 'attached' });

    let csrfToken =
        (await switcher.getAttribute('data-csrf-token')) ??
        (await readCsrfToken(context));

    const response = await page.request.post('/account/theme-preference', {
        data: { skin, mode, calm },
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-XSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
            Referer: `${baseURLFromPage(page)}/dashboard`,
        },
    });

    if (!response.ok()) {
        throw new Error(
            `Failed to save theme preference (${response.status()}).`,
        );
    }
}

export async function primeGuestAppearance(
    page: Page,
    skin: string,
    mode: string,
    calm: boolean,
): Promise<void> {
    await page.addInitScript(
        (appearance) => {
            localStorage.setItem(
                'myapes-appearance',
                JSON.stringify(appearance),
            );
        },
        { skin, mode, calm },
    );
}

export async function dismissBlockingOverlays(page: Page): Promise<void> {
    await page.keyboard.press('Escape');
    await page.evaluate(() => {
        document
            .querySelectorAll('.driver-popover, .driver-overlay')
            .forEach((node) => node.remove());
    });
}

export async function preparePage(
    page: Page,
    matrixPage: MatrixPage,
): Promise<void> {
    if (matrixPage.prepare !== 'mega-menu') {
        return;
    }

    await dismissBlockingOverlays(page);

    await page.evaluate(() => {
        const container = document.querySelector('[data-workspace-mega]');
        const trigger = container?.querySelector('[data-workspace-mega-trigger]');
        const panel = container?.querySelector('[data-workspace-mega-panel]');

        if (
            !(container instanceof HTMLElement) ||
            !(trigger instanceof HTMLElement) ||
            !(panel instanceof HTMLElement)
        ) {
            throw new Error('Workspace mega menu is not available on this page.');
        }

        panel.removeAttribute('hidden');
        trigger.setAttribute('aria-expanded', 'true');
        container.classList.add('is-open');
    });

    await page
        .locator('[data-workspace-mega].is-open .sg-mega__panel')
        .first()
        .waitFor({ state: 'attached' });
}

export async function settleChrome(page: Page): Promise<void> {
    await page
        .waitForFunction(() => (globalThis as { lucide?: { createIcons?: unknown } }).lucide?.createIcons !== undefined, {
            timeout: 5_000,
        })
        .catch(() => undefined);

    await page.evaluate(async () => {
        const mascot = document.querySelector('[data-mascot-dock]');

        if (mascot instanceof HTMLElement) {
            mascot.hidden = true;
            document.body.classList.remove('has-mascot-dock');
        }

        if (document.fonts?.ready) {
            await document.fonts.ready;
        }
    });
}