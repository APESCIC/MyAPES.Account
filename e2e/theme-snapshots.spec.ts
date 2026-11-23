import { expect, test } from '@playwright/test';
import {
    applyAppearance,
    assertAppReachable,
    ensureLoggedIn,
    loadMatrix,
    preparePage,
    primeGuestAppearance,
    settleChrome,
    snapshotName,
} from './theme-snapshot-helpers';

const baseURL =
    process.env.APP_URL ??
    process.env.BASE_URL ??
    'https://myapes-account.test';

const matrix = loadMatrix();

test.describe('Theme visual snapshots (#908)', () => {
    test.beforeAll(async () => {
        const reachable = await assertAppReachable(baseURL);

        if (!reachable) {
            test.skip(
                true,
                `App not reachable at ${baseURL}; set APP_URL or BASE_URL for Playwright.`,
            );
        }
    });

    for (const pageDef of matrix.pages) {
        for (const skin of matrix.skins) {
            for (const mode of matrix.modes) {
                for (const calm of matrix.calm) {
                    const calmEnabled = calm === 'on';
                    const shot = snapshotName(pageDef.key, skin, mode, calm);

                    test(`${pageDef.key}__${skin}__${mode}__calm-${calm}`, async ({
                        page,
                        context,
                    }) => {
                        if (pageDef.auth) {
                            await ensureLoggedIn(context, page, baseURL);
                            await applyAppearance(
                                context,
                                page,
                                skin,
                                mode,
                                calmEnabled,
                            );
                        } else {
                            await primeGuestAppearance(
                                page,
                                skin,
                                mode,
                                calmEnabled,
                            );
                        }

                        const response = await page.goto(pageDef.path, {
                            waitUntil: 'domcontentloaded',
                        });

                        if (pageDef.expectStatus === 404) {
                            expect(response?.status()).toBe(404);
                        } else {
                            expect(response?.ok()).toBeTruthy();
                        }

                        await settleChrome(page);
                        await preparePage(page, pageDef);

                        const root = page.locator('html');
                        await expect(root).toHaveAttribute('data-skin', skin);
                        await expect(root).toHaveAttribute('data-theme', mode);

                        if (calmEnabled) {
                            await expect(root).toHaveAttribute('data-calm', 'true');
                        } else {
                            await expect(root).not.toHaveAttribute('data-calm');
                        }

                        await expect(page).toHaveScreenshot(shot, {
                            animations: 'disabled',
                            fullPage: false,
                        });
                    });
                }
            }
        }
    }
});