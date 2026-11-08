import path from 'node:path';
import { fileURLToPath } from 'node:url';

import concurrently from 'concurrently';

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
} = {}) {
    const port = resolveAppPort(appPort);
    const logCommand =
        platform === 'win32'
            ? 'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local/tail-log.ps1'
            : 'php artisan pail --timeout=0';

    return [
        {
            command: `php artisan serve --host=127.0.0.1 --port=${port}`,
            name: 'server',
            prefixColor: '#93c5fd',
        },
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
        {
            command: 'npm run dev -- --host 127.0.0.1',
            name: 'vite',
            prefixColor: '#fdba74',
        },
    ];
}

export function buildDevRunnerOptions({ cwd = repositoryRoot } = {}) {
    return {
        cwd,
        handleInput: true,
        killOthersOn: ['success', 'failure'],
        prefix: 'name',
    };
}

export async function runDev() {
    const { result } = concurrently(
        buildDevCommands(),
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
