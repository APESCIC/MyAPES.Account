import { expect, test } from '@playwright/test';
import {
    applyAppearance,
    assertAppReachable,
    ensureLoggedIn,
    STAFF_EMAIL,
    STAFF_PASSWORD,
} from './theme-snapshot-helpers';

const baseURL =
    process.env.APP_URL ??
    process.env.BASE_URL ??
    'https://myapes-account.test';

test.describe('Theme FOUC guards (#908 / #978)', () => {
    test.beforeAll(async () => {
        if (!(await assertAppReachable(baseURL))) {
            test.skip(true, `App not reachable at ${baseURL}.`);
        }
    });

    test('signed-in dashboard keeps server skin on first paint', async ({
        page,
        context,
    }) => {
        await ensureLoggedIn(context, page, baseURL);
        await applyAppearance(context, page, 'jungle', 'dark', true);

        await page.goto('/dashboard', { waitUntil: 'commit' });

        const firstPaint = await page.evaluate(() => {
            const root = document.documentElement;

            return {
                skin: root.getAttribute('data-skin'),
                theme: root.getAttribute('data-theme'),
                calm: root.getAttribute('data-calm'),
                source: root.getAttribute('data-appearance-source'),
            };
        });

        expect(firstPaint.source).toBe('server');
        expect(firstPaint.skin).toBe('jungle');
        expect(firstPaint.theme).toBe('dark');
        expect(firstPaint.calm).toBe('true');
    });

    test('guest localStorage does not override when server source is set', async ({
        page,
        context,
    }) => {
        await page.goto('/staff/login', { waitUntil: 'domcontentloaded' });
        await page.locator('input[name="email"]').fill(STAFF_EMAIL);
        await page.locator('input[name="password"]').fill(STAFF_PASSWORD);
        await page.locator('button[type="submit"]').first().click();
        await page.waitForURL((url) => !url.pathname.endsWith('/staff/login'));

        await applyAppearance(context, page, 'glassy', 'light', false);

        await page.addInitScript(() => {
            localStorage.setItem(
                'myapes-appearance',
                JSON.stringify({ skin: 'jungle', mode: 'dark', calm: true }),
            );
        });

        await page.goto('/dashboard', { waitUntil: 'commit' });

        const attrs = await page.evaluate(() => ({
            skin: document.documentElement.getAttribute('data-skin'),
            theme: document.documentElement.getAttribute('data-theme'),
            source: document.documentElement.getAttribute(
                'data-appearance-source',
            ),
        }));

        expect(attrs.source).toBe('server');
        expect(attrs.skin).toBe('glassy');
        expect(attrs.theme).toBe('light');
    });
});