import assert from 'node:assert/strict';
import test from 'node:test';

import { bindPushDeviceRoot, bindPushLogoutCleanup } from './soft-prompt.js';

const config = {
    vapidUrl: '/push/vapid-public-key',
    storeUrl: '/push/subscriptions',
    destroyUrl: '/push/subscriptions',
    messages: {
        default: 'Notifications are off.',
        blocked: 'Blocked.',
        subscribed: 'On.',
        turned_off: 'Off.',
        dismissed: 'Later.',
        error: 'Error.',
    },
};

const makeElement = (tag, attrs = {}, children = []) => {
    const listeners = {};
    const el = {
        tagName: tag.toUpperCase(),
        hidden: false,
        disabled: false,
        dataset: attrs.dataset ?? {},
        textContent: '',
        attributes: { ...attrs },
        children,
        querySelector: (selector) => {
            const find = (nodes) => {
                for (const node of nodes) {
                    if (node.matches?.(selector)) {
                        return node;
                    }

                    const nested = find(node.children ?? []);

                    if (nested) {
                        return nested;
                    }
                }

                return null;
            };

            return find([el, ...children]);
        },
        querySelectorAll: (selector) => {
            const matches = [];
            const walk = (nodes) => {
                for (const node of nodes) {
                    if (node.matches?.(selector)) {
                        matches.push(node);
                    }

                    walk(node.children ?? []);
                }
            };

            walk([el, ...children]);

            return matches;
        },
        addEventListener: (type, handler) => {
            listeners[type] = handler;
        },
        click: () => listeners.click?.({ currentTarget: el, preventDefault() {} }),
        matches: (selector) => {
            if (selector === '[data-push-state]') {
                return attrs['data-push-state'] !== undefined || attrs.dataset?.pushState !== undefined;
            }

            if (selector === '[data-push-device]') {
                return Boolean(attrs['data-push-device'] !== undefined);
            }

            if (selector === '[data-push-turn-on]') {
                return attrs['data-push-turn-on'] !== undefined;
            }

            if (selector === '[data-push-not-now]') {
                return attrs['data-push-not-now'] !== undefined;
            }

            if (selector === '[data-push-status]') {
                return attrs['data-push-status'] !== undefined;
            }

            if (selector.startsWith('[data-push-state')) {
                return attrs['data-push-state'] === selector.match(/"(.+)"/)?.[1];
            }

            return false;
        },
    };

    Object.defineProperty(el, 'hasAttribute', {
        value: (name) => name in attrs || Object.keys(attrs).includes(name),
    });

    return el;
};

const buildRoot = () => {
    const status = makeElement('p', { 'data-push-status': '' });
    const defaultState = makeElement('div', { 'data-push-state': '', dataset: { pushState: 'default' } });
    const turnOn = makeElement('button', { 'data-push-turn-on': '', type: 'button' });
    const notNow = makeElement('button', { 'data-push-not-now': '', type: 'button', hidden: true });
    const turnOff = makeElement('button', { 'data-push-turn-off': '', type: 'button', hidden: true });

    const root = makeElement('div', {
        'data-push-device': '',
        'data-push-soft-prompt': '',
        dataset: { pushConfig: JSON.stringify(config) },
    }, [status, defaultState, turnOn, notNow, turnOff]);

    return { root, turnOn, notNow, status };
};

test('initialising the soft prompt does not call Notification.requestPermission', async () => {
    const { root } = buildRoot();
    const permissionCalls = [];

    bindPushDeviceRoot(root, {
        doc: {},
        win: {
            Notification: {
                permission: 'default',
                requestPermission: async () => {
                    permissionCalls.push(true);

                    return 'granted';
                },
            },
            PushManager: function PushManager() {},
            localStorage: { getItem: () => null, setItem: () => {} },
        },
        nav: {
            serviceWorker: { ready: Promise.resolve({ pushManager: { getSubscription: async () => null } }) },
        },
        storage: { getItem: () => null, setItem: () => {} },
    });

    await new Promise((resolve) => { setTimeout(resolve, 0); });

    assert.equal(permissionCalls.length, 0);
});

test('Turn on requests permission; Not now never does', async () => {
    const { root, turnOn, notNow } = buildRoot();
    const permissionCalls = [];
    const storage = { values: new Map(), getItem(k) { return this.values.get(k) ?? null; }, setItem(k, v) { this.values.set(k, v); } };

    bindPushDeviceRoot(root, {
        doc: {},
        requestPermission: async () => {
            permissionCalls.push('turn-on');

            return 'denied';
        },
        win: {
            Notification: { permission: 'default' },
            PushManager: function PushManager() {},
            localStorage: storage,
        },
        nav: {
            serviceWorker: { ready: Promise.resolve({ pushManager: { getSubscription: async () => null } }) },
        },
        storage,
    });

    await new Promise((resolve) => { setTimeout(resolve, 0); });

    notNow.hidden = false;
    notNow.click();
    assert.equal(permissionCalls.length, 0);
    assert.ok(storage.getItem('myapes-push-soft-prompt-snooze-until'));

    turnOn.click();
    await new Promise((resolve) => { setTimeout(resolve, 0); });
    assert.deepEqual(permissionCalls, ['turn-on']);
});

test('logout submit triggers unsubscribe clean-up', async () => {
    const listeners = {};
    const form = { getAttribute: (name) => (name === 'action' ? '/logout' : null) };
    const body = { dataset: { pushDestroyUrl: '/push/subscriptions' }, hasAttribute: () => false };
    const doc = {
        body,
        addEventListener: (type, handler, capture) => {
            if (type === 'submit' && capture) {
                listeners.submitCapture = handler;
            }
        },
    };

    let unsubscribed = false;

    bindPushLogoutCleanup(doc, {
        win: {},
        getRegistration: async () => ({
            pushManager: {
                getSubscription: async () => ({
                    endpoint: 'https://fcm.googleapis.com/fcm/send/device-1',
                    unsubscribe: async () => {
                        unsubscribed = true;

                        return true;
                    },
                }),
            },
        }),
        fetch: async () => ({ ok: true }),
    });

    listeners.submitCapture({ target: form });

    await new Promise((resolve) => { setTimeout(resolve, 0); });

    assert.equal(unsubscribed, true);
});