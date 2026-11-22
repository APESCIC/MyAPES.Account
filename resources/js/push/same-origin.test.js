import assert from 'node:assert/strict';
import { test } from 'node:test';

import { resolveSameOriginNotificationUrl } from './same-origin.js';

const origin = 'https://myapes-account.test';

test('relative paths resolve on the app origin', () => {
    assert.equal(resolveSameOriginNotificationUrl(origin, '/dashboard'), '/dashboard');
    assert.equal(resolveSameOriginNotificationUrl(origin, '/tickets/42?q=1#note'), '/tickets/42?q=1#note');
});

test('absolute same-origin URLs are normalised to path form', () => {
    assert.equal(
        resolveSameOriginNotificationUrl(origin, 'https://myapes-account.test/inbox'),
        '/inbox',
    );
});

test('outside origins and invalid input are ignored', () => {
    assert.equal(resolveSameOriginNotificationUrl(origin, 'https://evil.example/phish'), null);
    assert.equal(resolveSameOriginNotificationUrl(origin, '//evil.example/steal'), null);
    assert.equal(resolveSameOriginNotificationUrl(origin, 'javascript:alert(1)'), null);
    assert.equal(resolveSameOriginNotificationUrl(origin, ''), null);
    assert.equal(resolveSameOriginNotificationUrl(origin, null), null);
    assert.equal(resolveSameOriginNotificationUrl(origin, undefined), null);
});