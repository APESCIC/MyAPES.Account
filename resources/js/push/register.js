/**
 * Register the push service worker for signed-in users (#793).
 */
export function initPushServiceWorker(doc = document, win = window) {
    const root = doc.body;

    if (!(root instanceof HTMLElement) || !root.hasAttribute('data-push-register')) {
        return;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in win)) {
        return;
    }

    const swUrl = root.dataset.pushSwUrl ?? '/sw.js';

    navigator.serviceWorker
        .register(swUrl, { scope: '/', updateViaCache: 'none' })
        .then((registration) => {
            registration.update().catch(() => {
                // A failed update check does not block the current registration.
            });
        })
        .catch(() => {
            // Push stays optional when registration fails (unsupported browser, CSP, and so on).
        });
}