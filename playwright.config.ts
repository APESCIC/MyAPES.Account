import { defineConfig, devices } from '@playwright/test';

const baseURL =
    process.env.APP_URL ??
    process.env.BASE_URL ??
    'https://myapes-account.test';

const snapshotDir = 'tests/Browser/snapshots/theme';

export default defineConfig({
    testDir: './e2e',
    workers: 1,
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        viewport: { width: 1280, height: 800 },
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
    },
    expect: {
        toHaveScreenshot: {
            pathTemplate: `${snapshotDir}/{arg}{ext}`,
            maxDiffPixelRatio: 0.02,
        },
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});