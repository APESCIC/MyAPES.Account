export const TOAST_DISMISS_MS = 6000;

export const toastDismissDelay = (tone) => (tone === 'ok' ? TOAST_DISMISS_MS : null);

export const toastPoliteness = (tone) => (tone === 'error' ? 'assertive' : 'polite');

const announce = (root, toast) => {
    const message = toast.querySelector('[data-toast-message]');
    const announcer = root.querySelector(`[data-toast-announcer="${toastPoliteness(toast.dataset.toastTone)}"]`);
    if (!(message instanceof HTMLElement) || !(announcer instanceof HTMLElement)) {
        return;
    }

    window.setTimeout(() => {
        announcer.textContent = message.innerText.trim();
    }, 150);
};

const dismiss = (toast) => {
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 200);
};

const scheduleDismiss = (toast, delay) => {
    let timer = window.setTimeout(() => dismiss(toast), delay);
    const pause = () => window.clearTimeout(timer);
    const resume = () => {
        if (!toast.matches(':hover') && !toast.contains(document.activeElement)) {
            timer = window.setTimeout(() => dismiss(toast), delay);
        }
    };

    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('mouseleave', resume);
    toast.addEventListener('focusout', resume);
};

export const initToasts = (root = document) => {
    for (const toast of root.querySelectorAll('[data-toast]')) {
        announce(root, toast);

        toast.querySelector('[data-toast-dismiss]')?.addEventListener('click', () => dismiss(toast));

        const delay = toastDismissDelay(toast.dataset.toastTone);
        if (delay !== null) {
            scheduleDismiss(toast, delay);
        }
    }
};
