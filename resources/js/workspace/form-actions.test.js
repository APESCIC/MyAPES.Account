import assert from 'node:assert/strict';
import { test } from 'node:test';
import { confirmMessageFor, initFormActions } from './form-actions.js';

const fakeRoot = () => {
    const listeners = {};

    return {
        listeners,
        addEventListener: (type, handler) => {
            listeners[type] = handler;
        },
    };
};

test('confirmMessageFor reads a non-empty data-confirm', () => {
    assert.equal(confirmMessageFor({ dataset: { confirm: 'Delete this ticket?' } }), 'Delete this ticket?');
    assert.equal(confirmMessageFor({ dataset: { confirm: '  ' } }), null);
    assert.equal(confirmMessageFor({ dataset: {} }), null);
    assert.equal(confirmMessageFor(null), null);
});

test('a declined confirmation stops the submit', () => {
    const root = fakeRoot();
    initFormActions(root, () => false);
    let prevented = false;

    root.listeners.submit({ target: { dataset: { confirm: 'Sure?' } }, preventDefault: () => { prevented = true; } });

    assert.equal(prevented, true);
});

test('an accepted confirmation lets the submit through', () => {
    const root = fakeRoot();
    initFormActions(root, () => true);
    let prevented = false;

    root.listeners.submit({ target: { dataset: { confirm: 'Sure?' } }, preventDefault: () => { prevented = true; } });

    assert.equal(prevented, false);
});

test('data-auto-submit submits the field form on change', () => {
    const root = fakeRoot();
    initFormActions(root, () => true);
    let submitted = 0;

    root.listeners.change({ target: { dataset: { autoSubmit: '' }, form: { requestSubmit: () => { submitted += 1; } } } });
    root.listeners.change({ target: { dataset: {}, form: { requestSubmit: () => { submitted += 1; } } } });

    assert.equal(submitted, 1);
});
