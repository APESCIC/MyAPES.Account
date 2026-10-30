import assert from 'node:assert/strict';
import test from 'node:test';
import { TOAST_DISMISS_MS, toastDismissDelay, toastPoliteness } from './toasts.js';

test('save confirmations dismiss themselves', () => {
    assert.equal(toastDismissDelay('ok'), TOAST_DISMISS_MS);
});

test('error toasts stay until the user dismisses them', () => {
    assert.equal(toastDismissDelay('error'), null);
});

test('unknown tones never auto-dismiss', () => {
    assert.equal(toastDismissDelay(undefined), null);
});

test('errors are announced assertively and confirmations politely', () => {
    assert.equal(toastPoliteness('error'), 'assertive');
    assert.equal(toastPoliteness('ok'), 'polite');
});
