/* eslint-disable no-restricted-globals */
/**
 * Push-only service worker at scope / (#793).
 * Keep resolveSameOriginNotificationUrl aligned with resources/js/push/same-origin.js.
 */
const SW_VERSION = '1';

const DEFAULT_ICON = '/icons/pwa-192x192.png';
const DEFAULT_BADGE = '/icons/pwa-192x192.png';

const resolveSameOriginNotificationUrl = (origin, candidate) => {
    if (typeof candidate !== 'string') {
        return null;
    }

    const trimmed = candidate.trim();

    if (trimmed === '') {
        return null;
    }

    try {
        const resolved = new URL(trimmed, origin);

        if (resolved.origin !== origin) {
            return null;
        }

        return `${resolved.pathname}${resolved.search}${resolved.hash}`;
    } catch {
        return null;
    }
};

const parsePushPayload = (event) => {
    if (!event.data) {
        return { title: 'MyAPES Account', options: { icon: DEFAULT_ICON, badge: DEFAULT_BADGE } };
    }

    try {
        const payload = event.data.json();

        return {
            title: typeof payload.title === 'string' && payload.title.trim() !== '' ? payload.title : 'MyAPES Account',
            options: {
                body: typeof payload.body === 'string' ? payload.body : '',
                icon: typeof payload.icon === 'string' ? payload.icon : DEFAULT_ICON,
                badge: typeof payload.badge === 'string' ? payload.badge : DEFAULT_BADGE,
                tag: typeof payload.tag === 'string' ? payload.tag : undefined,
                data: {
                    url: typeof payload.url === 'string' ? payload.url : '/',
                },
            },
        };
    } catch {
        const text = event.data.text();

        return {
            title: text.trim() !== '' ? text : 'MyAPES Account',
            options: { icon: DEFAULT_ICON, badge: DEFAULT_BADGE, data: { url: '/' } },
        };
    }
};

const readCsrfHeader = async () => {
    if (!('cookieStore' in self)) {
        return {};
    }

    const cookie = await self.cookieStore.get('XSRF-TOKEN');

    if (!cookie?.value) {
        return {};
    }

    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) };
};

const urlBase64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
};

const fetchVapidPublicKey = async () => {
    const response = await fetch('/push/vapid-public-key', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('vapid-unavailable');
    }

    const body = await response.json();

    if (typeof body.publicKey !== 'string' || body.publicKey === '') {
        throw new Error('vapid-missing');
    }

    return body.publicKey;
};

const serialiseSubscription = (subscription) => {
    const json = subscription.toJSON();

    return {
        endpoint: json.endpoint,
        keys: json.keys,
        contentEncoding: json.contentEncoding ?? 'aes128gcm',
    };
};

const postSubscription = async (subscription) => {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(await readCsrfHeader()),
    };

    await fetch('/push/subscriptions', {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify(serialiseSubscription(subscription)),
    });
};

const resubscribeAfterChange = async () => {
    const registration = self.registration;
    const existing = await registration.pushManager.getSubscription();
    const applicationServerKey = await fetchVapidPublicKey();
    const options = {
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(applicationServerKey),
    };

    const next = existing ?? (await registration.pushManager.subscribe(options));

    await postSubscription(next);
};

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();

            await Promise.all(keys.map((key) => caches.delete(key)));
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('push', (event) => {
    const { title, options } = parsePushPayload(event);

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const origin = self.location.origin;
    const candidate = event.notification.data?.url;
    const targetPath = resolveSameOriginNotificationUrl(origin, candidate) ?? '/';

    event.waitUntil(
        (async () => {
            const windowClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            for (const client of windowClients) {
                const clientUrl = new URL(client.url);

                if (clientUrl.origin !== origin) {
                    continue;
                }

                if (clientUrl.pathname + clientUrl.search + clientUrl.hash === targetPath && 'focus' in client) {
                    await client.focus();

                    return;
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(targetPath);
            }
        })(),
    );
});

self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(resubscribeAfterChange());
});

// Version marker for update detection in tests and support tooling.
self.SW_VERSION = SW_VERSION;