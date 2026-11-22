import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const swPath = join(dirname(fileURLToPath(import.meta.url)), 'service-worker.js');

/**
 * @returns {{ listeners: Record<string, Function>, notifications: Array<{ title: string, options: object }>, self: object, fetchCalls: Array<{ url: string, init?: RequestInit }> }}
 */
const loadServiceWorker = (fetchImpl) => {
    const listeners = {};
    const notifications = [];
    const fetchCalls = [];

    const self = {
        addEventListener(type, fn) {
            listeners[type] = fn;
        },
        registration: {
            showNotification: async (title, options) => {
                notifications.push({ title, options });
            },
            pushManager: {
                getSubscription: async () => null,
                subscribe: async () => ({
                    toJSON() {
                        return {
                            endpoint: 'https://push.example/device',
                            keys: { p256dh: 'k', auth: 'a' },
                            contentEncoding: 'aes128gcm',
                        };
                    },
                }),
            },
        },
        location: { origin: 'https://myapes-account.test' },
        clients: {
            matchAll: async () => [],
            openWindow: async () => {},
        },
        skipWaiting: async () => {},
        claim: async () => {},
    };

    const fetchFn = fetchImpl ?? (async (url, init) => {
        fetchCalls.push({ url: String(url), init });

        if (String(url).includes('vapid-public-key')) {
            return {
                ok: true,
                json: async () => ({
                    publicKey: 'BEl62iUYgUivxIkv9yuoVe-VueBkzLKTI5UQLI0kPzHroj5eWS-FB71xXQB47w-Odk4w0vWc3lXkaF_WlDd_Ea4',
                }),
            };
        }

        return { ok: true, json: async () => ({}) };
    });

    const context = {
        self,
        console,
        atob,
        btoa,
        URL,
        Uint8Array,
        Array,
        Promise,
        fetch: fetchFn,
        caches: {
            keys: async () => [],
            delete: async () => true,
        },
        decodeURIComponent,
    };

    vm.createContext(context);
    vm.runInContext(readFileSync(swPath, 'utf8'), context, { filename: 'service-worker.js' });

    return { listeners, notifications, self, fetchCalls };
};

test('push handler shows a notification from declarative web push JSON', async () => {
    const { listeners, notifications } = loadServiceWorker();

    const event = {
        data: {
            json() {
                return {
                    web_push: 8030,
                    notification: {
                        title: 'Ticket update',
                        body: 'Something changed',
                        navigate: '/tickets/12',
                    },
                };
            },
        },
        waitUntil(promise) {
            return promise;
        },
    };

    await listeners.push(event);

    assert.equal(notifications.length, 1);
    assert.equal(notifications[0].title, 'Ticket update');
    assert.equal(notifications[0].options.body, 'Something changed');
    assert.equal(notifications[0].options.data.url, '/tickets/12');
});

test('notificationclick focuses a matching client or opens the target path', async () => {
    const { listeners, self } = loadServiceWorker();
    let focused = false;
    let opened = null;

    self.clients.matchAll = async () => ([
        {
            url: 'https://myapes-account.test/inbox',
            focus: async () => {
                focused = true;
            },
        },
    ]);
    self.clients.openWindow = async (path) => {
        opened = path;
    };

    const notification = {
        close() {},
        data: { url: '/inbox' },
    };

    const runClick = async () => {
        let pending;
        const event = {
            notification,
            waitUntil(promise) {
                pending = promise;

                return promise;
            },
        };

        listeners.notificationclick(event);
        await pending;
    };

    await runClick();

    assert.equal(focused, true);
    assert.equal(opened, null);

    focused = false;
    self.clients.matchAll = async () => ([
        { url: 'https://myapes-account.test/dashboard', focus: async () => { focused = true; } },
    ]);

    await runClick();

    assert.equal(focused, false);
    assert.equal(opened, '/inbox');
});

test('pushsubscriptionchange resubscribes and posts the subscription', async () => {
    const { listeners, fetchCalls } = loadServiceWorker();

    let pending;
    const event = {
        waitUntil(promise) {
            pending = promise;

            return promise;
        },
    };

    listeners.pushsubscriptionchange(event);
    await pending;

    const post = fetchCalls.find((call) => call.url === '/push/subscriptions' && call.init?.method === 'POST');
    assert.ok(post, 'expected POST /push/subscriptions');
    const body = JSON.parse(String(post.init?.body));
    assert.equal(body.endpoint, 'https://push.example/device');
    assert.equal(body.contentEncoding, 'aes128gcm');
});