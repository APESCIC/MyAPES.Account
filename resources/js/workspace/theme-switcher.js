const appearanceStorageKey = 'myapes-appearance';
const themeStorageKey = 'myapes-theme';
const guestCookieName = 'myapes-appearance';

const normaliseSkin = (skin) => (skin === 'jungle' ? 'jungle' : 'glassy');

const normaliseMode = (mode) => (mode === 'dark' ? 'dark' : 'light');

export const readAppearanceFromDocument = () => ({
    skin: normaliseSkin(document.documentElement.dataset.skin),
    mode: normaliseMode(document.documentElement.dataset.theme),
    calm: document.documentElement.hasAttribute('data-calm'),
});

const themeColor = () => document.querySelector('meta[name="theme-color"]');

const syncSwitcherUi = (appearance) => {
    for (const root of document.querySelectorAll('[data-theme-switcher]')) {
        for (const group of root.querySelectorAll('[data-appearance-key="skin"], [data-appearance-key="mode"]')) {
            const key = group.dataset.appearanceKey;
            const value = appearance[key];

            for (const option of group.querySelectorAll('[role="radio"]')) {
                const selected = option.dataset.appearanceValue === value;
                option.setAttribute('aria-checked', selected ? 'true' : 'false');
                option.tabIndex = selected ? 0 : -1;
            }
        }

        const calmControl = root.querySelector('[data-appearance-key="calm"]');
        if (calmControl instanceof HTMLElement) {
            calmControl.setAttribute('aria-checked', appearance.calm ? 'true' : 'false');
        }
    }
};

const persistGuestAppearance = (appearance) => {
    const payload = JSON.stringify({
        skin: appearance.skin,
        mode: appearance.mode,
        calm: appearance.calm,
    });

    try {
        localStorage.setItem(appearanceStorageKey, payload);
        localStorage.setItem(themeStorageKey, appearance.mode);
    } catch {
        // The selected theme still applies for the current page when storage is unavailable.
    }

    try {
        document.cookie = `${guestCookieName}=${encodeURIComponent(payload)};path=/;max-age=31536000;SameSite=Lax`;
    } catch {
        // Cookie persistence is best-effort for login merge.
    }
};

const persistAuthenticatedAppearance = (appearance, root) => {
    const url = root?.dataset.themePreferenceUrl;
    if (typeof url !== 'string' || url === '' || typeof fetch !== 'function') {
        return;
    }

    const csrfToken = root.dataset.csrfToken
        ?? document.querySelector('input[name="_token"]')?.value
        ?? '';

    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
            skin: appearance.skin,
            mode: appearance.mode,
            calm: appearance.calm,
        }),
    }).catch(() => {});
};

export const applyAppearance = (partial, { persist = false, sourceRoot = null } = {}) => {
    const current = readAppearanceFromDocument();
    const appearance = {
        skin: normaliseSkin(partial.skin ?? current.skin),
        mode: normaliseMode(partial.mode ?? current.mode),
        calm: partial.calm !== undefined ? Boolean(partial.calm) : current.calm,
    };

    document.documentElement.dataset.skin = appearance.skin;
    document.documentElement.dataset.theme = appearance.mode;
    document.documentElement.style.colorScheme = appearance.mode;

    if (appearance.calm) {
        document.documentElement.setAttribute('data-calm', 'true');
    } else {
        document.documentElement.removeAttribute('data-calm');
    }

    const isDark = appearance.mode === 'dark';
    themeColor()?.setAttribute('content', isDark ? '#0b1a1f' : '#d2f4fa');

    syncSwitcherUi(appearance);

    if (persist) {
        const authRoot = sourceRoot instanceof HTMLElement && sourceRoot.dataset.themePreferenceUrl
            ? sourceRoot
            : document.querySelector('[data-theme-switcher][data-theme-preference-url]');

        if (authRoot instanceof HTMLElement) {
            persistAuthenticatedAppearance(appearance, authRoot);
        } else {
            persistGuestAppearance(appearance);
        }
    }

    return appearance;
};

export const applyTheme = (theme, persist = false) => {
    applyAppearance({ mode: normaliseMode(theme) }, { persist });
};

const focusRadioInGroup = (group, target) => {
    for (const option of group.querySelectorAll('[role="radio"]')) {
        const selected = option === target;
        option.setAttribute('aria-checked', selected ? 'true' : 'false');
        option.tabIndex = selected ? 0 : -1;
    }

    target.focus();
};

const handleRadioGroupKeydown = (event) => {
    if (!(event.target instanceof HTMLElement) || event.target.getAttribute('role') !== 'radio') {
        return;
    }

    const group = event.target.closest('[role="radiogroup"]');
    if (!(group instanceof HTMLElement)) {
        return;
    }

    const options = [...group.querySelectorAll('[role="radio"]')];
    const index = options.indexOf(event.target);
    if (index < 0) {
        return;
    }

    let nextIndex = index;

    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        nextIndex = (index + 1) % options.length;
        event.preventDefault();
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        nextIndex = (index - 1 + options.length) % options.length;
        event.preventDefault();
    } else if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        selectRadio(event.target);

        return;
    } else {
        return;
    }

    focusRadioInGroup(group, options[nextIndex]);
    selectRadio(options[nextIndex]);
};

const selectRadio = (option) => {
    const group = option.closest('[role="radiogroup"]');
    const root = option.closest('[data-theme-switcher]');
    if (!(group instanceof HTMLElement)) {
        return;
    }

    focusRadioInGroup(group, option);

    const key = group.dataset.appearanceKey;
    const value = option.dataset.appearanceValue;
    if (key !== 'skin' && key !== 'mode') {
        return;
    }

    applyAppearance({ [key]: value }, { persist: true, sourceRoot: root });
};

export const initThemeSwitcher = () => {
    const appearance = readAppearanceFromDocument();
    syncSwitcherUi(appearance);

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const calm = event.target.closest('[data-appearance-key="calm"]');
        if (calm instanceof HTMLElement) {
            const root = calm.closest('[data-theme-switcher]');
            const next = !readAppearanceFromDocument().calm;
            applyAppearance({ calm: next }, { persist: true, sourceRoot: root });

            return;
        }

        const radio = event.target.closest('[role="radio"][data-appearance-value]');
        if (radio instanceof HTMLElement) {
            selectRadio(radio);
        }
    });

    document.addEventListener('keydown', handleRadioGroupKeydown);
};