import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5175);
    // Serve, reload and write public/hot under the APP_URL host name
    // (myapes-account.test on Herd), so no address is printed or used.
    const appUrl = new URL(env.APP_URL || 'http://localhost');
    const host = appUrl.hostname;
    // On an https APP_URL, serve Vite with Herd's certificate for that host, when
    // the site is secured; elsewhere (CI, builds, no Herd) Vite stays on http.
    const herdCertificate = path.join(os.homedir(), '.config', 'herd', 'config', 'valet', 'Certificates', `${host}.crt`);
    const detectTls = appUrl.protocol === 'https:' && fs.existsSync(herdCertificate) ? host : false;

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/js/admin-analytics.js',
                ],
                refresh: [
                    'resources/views/**',
                    'modules/**/resources/views/**',
                    'plugins/**/resources/views/**',
                    'routes/**',
                    'app/**',
                ],
                detectTls,
            }),
            tailwindcss(),
        ],
        server: {
            host,
            port,
            strictPort: true,
            hmr: {
                host,
                port,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
