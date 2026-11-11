import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import concurrently from 'concurrently';
import { loadEnv } from 'vite';

const runnerPath = fileURLToPath(import.meta.url);
const repositoryRoot = path.resolve(path.dirname(runnerPath), '..', '..');

export function resolveAppPort(value = '8000') {
    const candidate = String(value);

    if (!/^\d+$/.test(candidate)) {
        throw new Error('APP_PORT must be an integer from 1 through 65535.');
    }

    const port = Number(candidate);

    if (!Number.isInteger(port) || port < 1 || port > 65535) {
        throw new Error('APP_PORT must be an integer from 1 through 65535.');
    }

    return String(port);
}

export function buildDevCommands({
    platform = process.platform,
    appPort = process.env.APP_PORT,
    serve = process.env.APP_SERVE === '1',
    viteRunning = false,
} = {}) {
    const port = resolveAppPort(appPort);
    const logCommand =
        platform === 'win32'
            ? 'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local/tail-log.ps1'
            : 'php artisan pail --timeout=0';

    const commands = [];

    if (serve) {
        commands.push({
            command: `php artisan serve --host=127.0.0.1 --port=${port}`,
            name: 'server',
            prefixColor: '#93c5fd',
        });
    }

    commands.push(
        {
            command: 'php artisan queue:listen --tries=1 --timeout=0',
            name: 'queue',
            prefixColor: '#c4b5fd',
        },
        {
            command: logCommand,
            name: 'logs',
            prefixColor: '#fb7185',
        },
    );

    if (!viteRunning) {
        commands.push({
            command: 'npm run dev',
            name: 'vite',
            prefixColor: '#fdba74',
        });
    }

    return commands;
}

export function buildDevRunnerOptions({ cwd = repositoryRoot } = {}) {
    return {
        cwd,
        handleInput: true,
        killOthersOn: ['success', 'failure'],
        prefix: 'name',
    };
}

export function resolveViteServer(env) {
    return {
        host: new URL(env.APP_URL || 'http://localhost').hostname,
        port: Number(env.VITE_PORT || 5175),
    };
}

export function isListening({ host, port }, timeout = 1000) {
    return new Promise((resolve) => {
        const socket = net.connect({ host, port });
        const finish = (listening) => {
            socket.destroy();
            resolve(listening);
        };

        socket.setTimeout(timeout, () => finish(false));
        socket.once('connect', () => finish(true));
        socket.once('error', () => finish(false));
    });
}

export async function runDev() {
    const vite = resolveViteServer(loadEnv('development', repositoryRoot, ''));
    const viteRunning = await isListening(vite);

    if (viteRunning) {
        console.log(
            `Vite already runs on http://${vite.host}:${vite.port} (scripts/preview.ps1 starts it); reusing it.`,
        );
    }

    const { result } = concurrently(
        buildDevCommands({ viteRunning }),
        buildDevRunnerOptions(),
    );

    await result;
}

if (process.argv[1] && path.resolve(process.argv[1]) === runnerPath) {
    try {
        await runDev();
    } catch (error) {
        if (error instanceof Error) {
            console.error(error.message);
        }

        process.exitCode = 1;
    }
}
