import assert from 'node:assert/strict';
import test from 'node:test';

import {
    detectPushEnvironment,
    isSoftPromptSnoozed,
    shouldShowSoftPrompt,
    snoozeSoftPrompt,
    SOFT_PROMPT_SNOOZE_KEY,
    unsubscribeDevice,
} from './client.js';

test('detectPushEnvironment marks iOS browsers without Home Screen install', () => {
    const win = {
        matchMedia: () => ({ matches: false }),
        navigator: { standalone: false },
        Notification: {},
        PushManager: function PushManager() {},
    };
    const nav = {
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
        maxTouchPoints: 5,
        serviceWorker: {},
    };

    assert.equal(detectPushEnvironment(win, nav).kind, 'home_screen');
});

test('snooze hides the soft prompt for thirty days', () => {
    const storage = {
        values: new Map(),
        getItem: (key) => storage.values.get(key) ?? null,
        setItem: (key, value) => storage.values.set(key, value),
    };
    const now = Date.parse('2026-11-22T12:00:00.000Z');

    snoozeSoftPrompt(storage, now);
    assert.ok(isSoftPromptSnoozed(now + 1, storage));
    assert.ok(isSoftPromptSnoozed(now + (29 * 24 * 60 * 60 * 1000), storage));
    assert.equal(isSoftPromptSnoozed(now + (31 * 24 * 60 * 60 * 1000), storage), false);
    assert.ok(storage.getItem(SOFT_PROMPT_SNOOZE_KEY));
});

test('soft prompt only appears for default permission before subscribe', () => {
    const base = {
        environment: { kind: 'supported' },
        snoozed: false,
        softMode: true,
    };

    assert.equal(
        shouldShowSoftPrompt({ ...base, permission: 'default', hasSubscription: false }),
        true,
    );
    assert.equal(
        shouldShowSoftPrompt({ ...base, permission: 'granted', hasSubscription: true }),
        false,
    );
    assert.equal(
        shouldShowSoftPrompt({ ...base, permission: 'denied', hasSubscription: false }),
        false,
    );
    assert.equal(
        shouldShowSoftPrompt({ ...base, permission: 'default', hasSubscription: false, snoozed: true }),
        false,
    );
});

test('unsubscribeDevice removes the browser subscription and deletes it on the server', async () => {
    const deleteCalls = [];
    let unsubscribed = false;
    const subscription = {
        endpoint: 'https://fcm.googleapis.com/fcm/send/device-1',
        unsubscribe: async () => {
            unsubscribed = true;

            return true;
        },
    };

    await unsubscribeDevice({
        destroyUrl: '/push/subscriptions',
        fetchFn: async (url, init) => {
            deleteCalls.push({ url, method: init.method, body: init.body });

            return { ok: true };
        },
        getRegistration: async () => ({
            pushManager: {
                getSubscription: async () => subscription,
            },
        }),
    });

    assert.equal(unsubscribed, true);
    assert.equal(deleteCalls.length, 1);
    assert.equal(deleteCalls[0].method, 'DELETE');
    assert.match(deleteCalls[0].body, /device-1/);
});