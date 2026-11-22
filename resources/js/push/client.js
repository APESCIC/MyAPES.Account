/**
 * Browser push subscribe and unsubscribe from the page context (#794).
 */

export const SOFT_PROMPT_SNOOZE_KEY = 'myapes-push-soft-prompt-snooze-until';

export const SNOOZE_DAYS = 30;

const DAY_MS = 24 * 60 * 60 * 1000;

export const detectPushEnvironment = (win = window, nav = navigator) => {
    const hasApis = 'serviceWorker' in nav && 'PushManager' in win && 'Notification' in win;

    if (!hasApis) {
        return { kind: 'unsupported' };
    }

    const ua = nav.userAgent ?? '';
    const isIos = /iPad|iPhone|iPod/i.test(ua)
        || (ua.includes('Macintosh') && 'maxTouchPoints' in nav && nav.maxTouchPoints > 1);
    const isStandalone = win.matchMedia?.('(display-mode: standalone)')?.matches === true
        || win.navigator?.standalone === true;

    if (isIos && !isStandalone) {
        return { kind: 'home_screen' };
    }

    return { kind: 'supported' };
};

export const readNotificationPermission = (win = window) => {
    if (!('Notification' in win)) {
        return 'unsupported';
    }

    return win.Notification.permission;
};

export const readSnoozeUntil = (storage, key = SOFT_PROMPT_SNOOZE_KEY) => {
    if (!storage) {
        return null;
    }

    try {
        const raw = storage.getItem(key);

        if (typeof raw !== 'string' || raw === '') {
            return null;
        }

        const until = Date.parse(raw);

        return Number.isNaN(until) ? null : until;
    } catch {
        return null;
    }
};

export const isSoftPromptSnoozed = (now = Date.now(), storage = null, key = SOFT_PROMPT_SNOOZE_KEY) => {
    const until = readSnoozeUntil(storage, key);

    return until !== null && until > now;
};

export const snoozeSoftPrompt = (
    storage,
    now = Date.now(),
    days = SNOOZE_DAYS,
    key = SOFT_PROMPT_SNOOZE_KEY,
) => {
    if (!storage) {
        return;
    }

    try {
        const until = new Date(now + days * DAY_MS);
        storage.setItem(key, until.toISOString());
    } catch {
        // Snooze is best-effort when storage is unavailable.
    }
};

export const urlBase64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
};

const readCsrfHeader = (doc = globalThis.document) => {
    const cookie = doc?.cookie;

    if (typeof cookie !== 'string' || cookie === '') {
        return {};
    }

    const match = cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    if (!match?.[1]) {
        return {};
    }

    return { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) };
};

const serialiseSubscription = (subscription) => {
    const json = subscription.toJSON();

    return {
        endpoint: json.endpoint,
        keys: json.keys,
        contentEncoding: json.contentEncoding ?? 'aes128gcm',
    };
};

export const fetchVapidPublicKey = async (url, fetchFn = fetch) => {
    const response = await fetchFn(url, {
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

export const savePushSubscription = async (subscription, storeUrl, fetchFn = fetch) => {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...readCsrfHeader(),
    };

    const response = await fetchFn(storeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify(serialiseSubscription(subscription)),
    });

    if (!response.ok) {
        throw new Error('save-failed');
    }
};

export const deletePushSubscription = async (endpoint, destroyUrl, fetchFn = fetch) => {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...readCsrfHeader(),
    };

    const response = await fetchFn(destroyUrl, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({ endpoint }),
    });

    if (!response.ok) {
        throw new Error('delete-failed');
    }
};

export const getServiceWorkerRegistration = async (nav = navigator) => {
    if (!('serviceWorker' in nav)) {
        throw new Error('no-service-worker');
    }

    return nav.serviceWorker.ready;
};

export const readBrowserSubscription = async (registration) => {
    return registration.pushManager.getSubscription();
};

/**
 * @param {object} options
 * @param {() => Promise<PermissionState>} [options.requestPermission] - only called from explicit user action
 */
export const subscribeAfterUserConsent = async ({
    vapidUrl,
    storeUrl,
    requestPermission,
    fetchFn = fetch,
    getRegistration = getServiceWorkerRegistration,
    permissionApi = globalThis.Notification,
    win = globalThis,
} = {}) => {
    if (!permissionApi || !win.PushManager) {
        throw new Error('unsupported');
    }

    const request = requestPermission
        ?? (() => Promise.resolve(permissionApi.requestPermission()));

    const permission = await request();

    if (permission !== 'granted') {
        return { permission };
    }

    const registration = await getRegistration();
    const applicationServerKey = await fetchVapidPublicKey(vapidUrl, fetchFn);
    const existing = await registration.pushManager.getSubscription();
    const options = {
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(applicationServerKey),
    };

    const subscription = existing ?? (await registration.pushManager.subscribe(options));
    await savePushSubscription(subscription, storeUrl, fetchFn);

    return { permission, endpoint: subscription.endpoint };
};

export const unsubscribeDevice = async ({
    destroyUrl,
    fetchFn = fetch,
    getRegistration = getServiceWorkerRegistration,
} = {}) => {
    const registration = await getRegistration();
    const subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        return { removed: false };
    }

    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();
    await deletePushSubscription(endpoint, destroyUrl, fetchFn);

    return { removed: true, endpoint };
};

export const resolveDeviceState = ({
    environment,
    permission,
    hasSubscription,
}) => {
    if (environment.kind === 'unsupported') {
        return 'unsupported';
    }

    if (environment.kind === 'home_screen') {
        return 'home_screen';
    }

    if (permission === 'denied') {
        return 'blocked';
    }

    if (hasSubscription && permission === 'granted') {
        return 'subscribed';
    }

    if (permission === 'granted') {
        return 'granted';
    }

    return 'default';
};

export const shouldShowSoftPrompt = ({
    environment,
    permission,
    hasSubscription,
    snoozed,
    softMode,
}) => {
    if (!softMode || snoozed) {
        return false;
    }

    const state = resolveDeviceState({ environment, permission, hasSubscription });

    return state === 'default' || state === 'home_screen';
};