import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5175);

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
            host: '127.0.0.1',
            port,
            strictPort: true,
            hmr: {
                host: '127.0.0.1',
                port,
            },
            cors: true,
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
