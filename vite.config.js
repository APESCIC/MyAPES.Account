import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// The Docker node container sets VITE_DEV_HOST and VITE_PORT; host runs keep 127.0.0.1:5173.
const devHost = process.env.VITE_DEV_HOST;
const devPort = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
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
        host: devHost ? '0.0.0.0' : '127.0.0.1',
        port: devPort,
        strictPort: Boolean(devHost),
        origin: devHost ? `http://${devHost}:${devPort}` : undefined,
        hmr: {
            host: devHost ?? '127.0.0.1',
            port: devPort,
        },
        cors: true,
        watch: {
            // File events from a Windows bind mount do not reach the container, so poll there.
            usePolling: Boolean(devHost),
            interval: 300,
            ignored: [
                '**/storage/**',
                '**/vendor/**',
                '**/database/*.sqlite*',
            ],
        },
    },
});
