import assert from 'node:assert/strict';
import test from 'node:test';
import {
    filterCommands,
    iconExportName,
    isPaletteShortcut,
    moveIndex,
    sameOriginTarget,
    shortcutLabel,
} from './command-palette.js';

const commands = [
    { id: 'staff:dashboard', label: 'Dashboard', section: 'Staff · Overview', keywords: 'Staff Overview home summary' },
    { id: 'staff:module.shelter-rescue.tickets', label: 'Tickets', section: 'Staff · APES Shelter and Rescue', keywords: 'helpdesk support' },
    { id: 'admin:admin.access', label: 'Access', section: 'Admin · People', keywords: 'permissions roles groups' },
    { id: 'action:create.shelter-rescue.tickets', label: 'Create ticket', section: 'Quick actions · APES Shelter and Rescue', keywords: 'new add create Tickets' },
];

test('an empty query returns every command', () => {
    assert.deepEqual(filterCommands(commands, '   '), commands);
});

test('every term must match label, section or keywords', () => {
    assert.deepEqual(filterCommands(commands, 'shelter tickets').map((command) => command.id), [
        'staff:module.shelter-rescue.tickets',
        'action:create.shelter-rescue.tickets',
    ]);
    assert.deepEqual(filterCommands(commands, 'permissions').map((command) => command.id), ['admin:admin.access']);
    assert.deepEqual(filterCommands(commands, 'shelter permissions'), []);
});

test('matching ignores case and accents', () => {
    assert.equal(filterCommands([{ label: 'Café rota', section: '', keywords: '' }], 'CAFE').length, 1);
});

test('arrow keys wrap around the results', () => {
    assert.equal(moveIndex(0, -1, 4), 3);
    assert.equal(moveIndex(3, 1, 4), 0);
    assert.equal(moveIndex(1, 1, 4), 2);
    assert.equal(moveIndex(0, 1, 0), 0);
});

test('the shortcut hint follows the platform', () => {
    assert.equal(shortcutLabel('MacIntel'), '⌘K');
    assert.equal(shortcutLabel('iPhone'), '⌘K');
    assert.equal(shortcutLabel('Win32'), 'Ctrl K');
});

test('Ctrl K and Cmd K open the palette, other keys do not', () => {
    assert.equal(isPaletteShortcut({ ctrlKey: true, key: 'k' }), true);
    assert.equal(isPaletteShortcut({ metaKey: true, key: 'K' }), true);
    assert.equal(isPaletteShortcut({ ctrlKey: true, altKey: true, key: 'k' }), false);
    assert.equal(isPaletteShortcut({ key: 'k' }), false);
    assert.equal(isPaletteShortcut({ ctrlKey: true, key: 'j' }), false);
});

test('only same-origin http links are opened', () => {
    const origin = 'https://myaccount.myapes.me.uk';

    assert.equal(sameOriginTarget('https://myaccount.myapes.me.uk/shelter/tickets#create', origin), '/shelter/tickets#create');
    assert.equal(sameOriginTarget('/admin/users?account_type=staff', origin), '/admin/users?account_type=staff');
    assert.equal(sameOriginTarget('javascript:alert(1)', origin), null);
    assert.equal(sameOriginTarget('https://example.com/phish', origin), null);
    assert.equal(sameOriginTarget('//example.com/phish', origin), null);
});

test('icon names map to lucide export names', () => {
    assert.equal(iconExportName('circle-user-round'), 'CircleUserRound');
    assert.equal(iconExportName('building-2'), 'Building2');
});
