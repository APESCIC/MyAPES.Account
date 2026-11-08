import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildDevCommands,
    buildDevRunnerOptions,
    resolveAppPort,
} from './dev-runner.mjs';

function commandNamed(commands, name) {
    return commands.find((command) => command.name === name);
}

test('native Windows uses a PowerShell log tail and never starts Pail', () => {
    const commands = buildDevCommands({
        platform: 'win32',
        appPort: '8123',
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
    assert.equal(
        commandNamed(commands, 'vite').command,
        'npm run dev -- --host 127.0.0.1',
    );
    assert.doesNotMatch(
        commands.map((command) => command.command).join('\n'),
        /artisan pail/i,
    );
});

test('Unix-like platforms retain Laravel Pail for application logs', () => {
    for (const platform of ['linux', 'darwin']) {
        const commands = buildDevCommands({ platform });

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
