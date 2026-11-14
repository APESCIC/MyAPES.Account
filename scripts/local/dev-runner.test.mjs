import assert from 'node:assert/strict';
import test from 'node:test';

import net from 'node:net';

import {
    buildDevCommands,
    buildDevRunnerOptions,
    isListening,
    resolveAppPort,
    resolveViteServer,
} from './dev-runner.mjs';

function commandNamed(commands, name) {
    return commands.find((command) => command.name === name);
}

test('Herd default omits artisan serve because nginx serves HTTP', () => {
    const commands = buildDevCommands({
        platform: 'win32',
        serve: false,
    });

    assert.deepEqual(
        commands.map((command) => command.name),
        ['queue', 'logs', 'vite'],
    );
    assert.equal(commandNamed(commands, 'server'), undefined);
});

test('native Windows uses a PowerShell log tail and never starts Pail', () => {
    const commands = buildDevCommands({
        platform: 'win32',
        appPort: '8123',
        serve: true,
    });

    assert.deepEqual(
        commands.map((command) => command.name),
        ['server', 'queue', 'logs', 'vite'],
    );
    assert.equal(
        commandNamed(commands, 'server').command,
        'php artisan serve --host=127.0.0.1 --port=8123',
    );
    assert.equal(
        commandNamed(commands, 'queue').command,
        'php artisan queue:listen --tries=1 --timeout=0',
    );
    assert.equal(
        commandNamed(commands, 'logs').command,
        'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local/tail-log.ps1',
    );
    assert.equal(commandNamed(commands, 'vite').command, 'npm run dev');
    assert.doesNotMatch(
        commands.map((command) => command.command).join('\n'),
        /artisan pail/i,
    );
});

test('a Vite already started by the preview gate is reused, not started twice', () => {
    const commands = buildDevCommands({
        platform: 'win32',
        serve: false,
        viteRunning: true,
    });

    assert.deepEqual(
        commands.map((command) => command.name),
        ['queue', 'logs'],
    );
});

test('Vite runs on the APP_URL host name and VITE_PORT', () => {
    assert.deepEqual(
        resolveViteServer({
            APP_URL: 'http://myapes-account.test',
            VITE_PORT: '5175',
        }),
        { host: 'myapes-account.test', port: 5175 },
    );
    assert.deepEqual(resolveViteServer({}), { host: 'localhost', port: 5175 });
});

test('isListening reports whether something accepts connections', async () => {
    const server = net.createServer();
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));
    const { port } = server.address();

    assert.equal(await isListening({ host: 'localhost', port }), true);
    await new Promise((resolve) => server.close(resolve));
    assert.equal(await isListening({ host: 'localhost', port }), false);
});

test('Unix-like platforms retain Laravel Pail for application logs', () => {
    for (const platform of ['linux', 'darwin']) {
        const commands = buildDevCommands({ platform, serve: true });

        assert.equal(
            commandNamed(commands, 'logs').command,
            'php artisan pail --timeout=0',
        );
    }
});

test('APP_PORT defaults to 8000 and accepts only the TCP port range', () => {
    assert.equal(resolveAppPort(), '8000');
    assert.equal(resolveAppPort('1'), '1');
    assert.equal(resolveAppPort('00080'), '80');
    assert.equal(resolveAppPort('65535'), '65535');

    for (const invalidPort of [
        '',
        '0',
        '65536',
        '-1',
        '8000.5',
        'not-a-port',
        '8000; echo unsafe',
    ]) {
        assert.throws(
            () => resolveAppPort(invalidPort),
            /APP_PORT must be an integer from 1 through 65535/,
        );
    }
});

test('the runner shuts down peer processes whenever one child exits', () => {
    assert.deepEqual(
        buildDevRunnerOptions({ cwd: 'C:/workspace' }),
        {
            cwd: 'C:/workspace',
            handleInput: true,
            killOthersOn: ['success', 'failure'],
            prefix: 'name',
        },
    );
});
