import assert from 'node:assert/strict';
import test from 'node:test';
import { TOAST_DISMISS_MS, toastDismissDelay } from './toasts.js';

test('save confirmations dismiss themselves', () => {
    assert.equal(toastDismissDelay('ok'), TOAST_DISMISS_MS);
});

test('error toasts stay until the user dismisses them', () => {
    assert.equal(toastDismissDelay('error'), null);
});

test('unknown tones never auto-dismiss', () => {
    assert.equal(toastDismissDelay(undefined), null);
});
