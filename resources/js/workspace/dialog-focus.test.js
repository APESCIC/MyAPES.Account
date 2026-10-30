import assert from 'node:assert/strict';
import test from 'node:test';
import { wrapFocusTarget } from './dialog-focus.js';

const first = { name: 'close' };
const middle = { name: 'link' };
const last = { name: 'sign-out' };
const elements = [first, middle, last];

test('Tab on the last element wraps to the first', () => {
    assert.equal(wrapFocusTarget(elements, last, false), first);
});

test('Shift+Tab on the first element wraps to the last', () => {
    assert.equal(wrapFocusTarget(elements, first, true), last);
});

test('focus outside the dialog is pulled back in', () => {
    assert.equal(wrapFocusTarget(elements, { name: 'page' }, false), first);
    assert.equal(wrapFocusTarget(elements, { name: 'page' }, true), last);
});

test('Tab between inner elements is left to the browser', () => {
    assert.equal(wrapFocusTarget(elements, first, false), null);
    assert.equal(wrapFocusTarget(elements, middle, true), null);
});

test('an empty dialog has nowhere to move focus', () => {
    assert.equal(wrapFocusTarget([], first, false), null);
});
