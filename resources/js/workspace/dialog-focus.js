const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'summary',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

export const focusableWithin = (container) => Array.from(container.querySelectorAll(FOCUSABLE)).filter(
    (element) => !element.closest('[hidden]')
        && element.getAttribute('aria-hidden') !== 'true'
        && element.getClientRects().length > 0,
);

/**
 * Where Tab or Shift+Tab should wrap to, or null to let the browser move focus.
 */
export const wrapFocusTarget = (elements, active, shiftKey) => {
    if (elements.length === 0) {
        return null;
    }

    const index = elements.indexOf(active);

    if (shiftKey && index <= 0) {
        return elements.at(-1);
    }

    if (!shiftKey && (index === -1 || index === elements.length - 1)) {
        return elements[0];
    }

    return null;
};

/**
 * Modal focus handling for the drawer and the command palette (#387): moves
 * focus in, traps Tab, closes on Escape, locks scrolling and makes the rest of
 * the page inert. Returns a release function that undoes all of it.
 */
export const trapDialog = (container, {
    onClose,
    initialFocus = null,
    fallbackFocus = null,
    inert = [],
    lockScroll = true,
} = {}) => {
    const previouslyFocused = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
        ? document.activeElement
        : null;
    const previousOverflow = document.body.style.overflow;

    if (lockScroll) {
        document.body.style.overflow = 'hidden';
    }

    const inerted = inert.filter(Boolean).map((element) => {
        const previous = element.getAttribute('aria-hidden');
        element.inert = true;
        element.setAttribute('aria-hidden', 'true');

        return [element, previous];
    });

    const initial = (initialFocus ? container.querySelector(initialFocus) : null) ?? focusableWithin(container)[0];
    initial?.focus();

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose?.();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const next = wrapFocusTarget(focusableWithin(container), document.activeElement, event.shiftKey);
        if (next) {
            event.preventDefault();
            next.focus();
        }
    };

    document.addEventListener('keydown', onKeyDown);

    return ({ restoreFocus = true } = {}) => {
        document.removeEventListener('keydown', onKeyDown);

        if (lockScroll) {
            document.body.style.overflow = previousOverflow;
        }

        for (const [element, previous] of inerted) {
            element.inert = false;
            if (previous === null) {
                element.removeAttribute('aria-hidden');
            } else {
                element.setAttribute('aria-hidden', previous);
            }
        }

        if (!restoreFocus) {
            return;
        }

        if (previouslyFocused?.isConnected && previouslyFocused.getClientRects().length > 0) {
            previouslyFocused.focus();
        } else {
            fallbackFocus?.focus();
        }
    };
};
