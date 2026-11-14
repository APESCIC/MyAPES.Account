import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5175);
    // Serve, reload and write public/hot under the APP_URL host name
    // (myapes-account.test on Herd), so no address is printed or used.
    const host = new URL(env.APP_URL || 'http://localhost').hostname;
    // When the site is served over HTTPS (herd secure myapes-account), Vite must speak
    // HTTPS too: browsers block plain-HTTP CSS and JavaScript from an HTTPS page as
    // mixed content. Reuse Herd's locally trusted certificate for the host, and fall
    // back to plain HTTP when the site itself is not secured or has no certificate yet.
    const tls = path.join(process.env.USERPROFILE || process.env.HOME, '.config', 'herd', 'config', 'valet', 'Certificates');
    const https = env.APP_URL?.startsWith('https://') && fs.existsSync(path.join(tls, `${host}.key`)) && fs.existsSync(path.join(tls, `${host}.crt`))
        ? {
            key: fs.readFileSync(path.join(tls, `${host}.key`)),
            cert: fs.readFileSync(path.join(tls, `${host}.crt`)),
        }
        : undefined;

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
            }),
            tailwindcss(),
        ],
        server: {
            host,
            port,
            strictPort: true,
            https,
            hmr: {
                host,
                port,
                protocol: https ? 'wss' : 'ws',
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
