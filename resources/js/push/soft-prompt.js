import {
    detectPushEnvironment,
    isSoftPromptSnoozed,
    readBrowserSubscription,
    readNotificationPermission,
    resolveDeviceState,
    shouldShowSoftPrompt,
    snoozeSoftPrompt,
    subscribeAfterUserConsent,
    unsubscribeDevice,
} from './client.js';

const readConfig = (root) => {
    const raw = root.dataset.pushConfig;

    if (typeof raw !== 'string' || raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
};

const announceStatus = (root, message) => {
    const live = root.querySelector('[data-push-status]');

    if (!live || typeof message !== 'string' || message === '') {
        return;
    }

    live.textContent = message;
};

export const setVisibleState = (root, state, messages) => {
    const regions = root.querySelectorAll('[data-push-state]');

    regions.forEach((region) => {
        if (!region || typeof region !== 'object') {
            return;
        }

        const regionState = region.getAttribute?.('data-push-state')
            ?? region.dataset?.pushState
            ?? '';
        const show = regionState === state;
        region.hidden = !show;
    });

    const turnOn = root.querySelector('[data-push-turn-on]');
    const turnOff = root.querySelector('[data-push-turn-off]');
    const notNow = root.querySelector('[data-push-not-now]');

    const canSubscribe = state === 'default' || state === 'granted';
    const showTurnOn = canSubscribe;
    const showTurnOff = state === 'subscribed';

    if (turnOn && 'hidden' in turnOn) {
        turnOn.hidden = !showTurnOn;
        turnOn.disabled = !showTurnOn;
    }

    if (turnOff && 'hidden' in turnOff) {
        turnOff.hidden = !showTurnOff;
        turnOff.disabled = !showTurnOff;
    }

    if (notNow && 'hidden' in notNow) {
        const softMode = root.hasAttribute('data-push-soft-prompt');
        const mayDismiss = state === 'default' || state === 'home_screen';
        notNow.hidden = !softMode || !mayDismiss;
    }

    const actions = root.querySelector('[data-push-actions]');

    if (actions && 'hidden' in actions) {
        actions.hidden = !showTurnOn && !showTurnOff;
    }

    const message = messages?.[state];

    if (typeof message === 'string') {
        announceStatus(root, message);
    }
};

const refreshDeviceUi = async (root, win, nav, storage) => {
    const config = readConfig(root);

    if (!config) {
        return;
    }

    const environment = detectPushEnvironment(win, nav);
    const permission = readNotificationPermission(win);
    let hasSubscription = false;

    if (environment.kind === 'supported' && 'serviceWorker' in nav) {
        try {
            const registration = await nav.serviceWorker.ready;
            const subscription = await readBrowserSubscription(registration);
            hasSubscription = subscription !== null;
        } catch {
            hasSubscription = false;
        }
    }

    const state = resolveDeviceState({ environment, permission, hasSubscription });
    setVisibleState(root, state, config.messages);

    const softMode = root.hasAttribute('data-push-soft-prompt');
    const snoozed = isSoftPromptSnoozed(Date.now(), storage);
    const showSoft = shouldShowSoftPrompt({
        environment,
        permission,
        hasSubscription,
        snoozed,
        softMode,
    });

    if (softMode) {
        root.hidden = !showSoft;
    }
};

export const bindPushDeviceRoot = (root, options = {}) => {
    const win = options.win ?? globalThis.window;
    const nav = options.nav ?? globalThis.navigator;
    const storage = options.storage ?? win?.localStorage;
    const doc = options.doc ?? globalThis.document;
    const config = readConfig(root);

    if (!config) {
        return;
    }

    const refresh = () => refreshDeviceUi(root, win, nav, storage);

    root.querySelector('[data-push-turn-on]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;

        if (!button || typeof button !== 'object' || !('disabled' in button)) {
            return;
        }

        button.disabled = true;

        try {
            const subscribeOptions = {
                vapidUrl: config.vapidUrl,
                storeUrl: config.storeUrl,
                fetchFn: options.fetch,
                getRegistration: options.getRegistration,
                permissionApi: win.Notification,
                win,
            };

            if (typeof options.requestPermission === 'function') {
                subscribeOptions.requestPermission = options.requestPermission;
            }

            const result = await subscribeAfterUserConsent(subscribeOptions);

            if (result.permission !== 'granted') {
                announceStatus(root, config.messages?.blocked ?? config.messages?.default ?? '');
            }
        } catch {
            announceStatus(root, config.messages?.error ?? config.messages?.default ?? '');
        } finally {
            await refresh();
            const turnOn = root.querySelector('[data-push-turn-on]');
            if (turnOn && !turnOn.hidden && 'disabled' in turnOn) {
                turnOn.disabled = false;
            }
        }
    });

    root.querySelector('[data-push-turn-off]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;

        if (!button || typeof button !== 'object' || !('disabled' in button)) {
            return;
        }

        button.disabled = true;

        try {
            await unsubscribeDevice({
                destroyUrl: config.destroyUrl,
                fetchFn: options.fetch,
                getRegistration: options.getRegistration,
            });
            announceStatus(root, config.messages?.turned_off ?? '');
        } catch {
            announceStatus(root, config.messages?.error ?? '');
        } finally {
            await refresh();
            const turnOff = root.querySelector('[data-push-turn-off]');
            if (turnOff && !turnOff.hidden && 'disabled' in turnOff) {
                turnOff.disabled = false;
            }
        }
    });

    root.querySelector('[data-push-not-now]')?.addEventListener('click', () => {
        snoozeSoftPrompt(storage);
        root.hidden = true;
        announceStatus(root, config.messages?.dismissed ?? '');
    });

    void refresh();

    return { refresh };
};

export const bindPushLogoutCleanup = (doc, options = {}) => {
    const win = options.win ?? globalThis.window;
    const body = doc?.body;

    if (!body || typeof body !== 'object') {
        return;
    }

    const destroyUrl = body.dataset?.pushDestroyUrl ?? '';

    if (destroyUrl === '') {
        return;
    }

    doc.addEventListener(
        'submit',
        (event) => {
            const form = event.target;

            if (!form || typeof form.getAttribute !== 'function') {
                return;
            }

            const action = form.getAttribute('action') ?? '';

            if (!action.includes('/logout')) {
                return;
            }

            void unsubscribeDevice({
                destroyUrl,
                fetchFn: options.fetch,
                getRegistration: options.getRegistration,
            }).catch(() => {
                // Sign-out must not wait on push clean-up.
            });
        },
        true,
    );
};

export function initPushSoftPrompt(doc = globalThis.document, win = globalThis.window) {
    doc?.querySelectorAll?.('[data-push-device]')?.forEach((root) => {
        bindPushDeviceRoot(root, { doc, win });
    });

    bindPushLogoutCleanup(doc, { win });
}