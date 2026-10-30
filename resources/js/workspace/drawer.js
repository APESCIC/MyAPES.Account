import { trapDialog } from './dialog-focus.js';

const DRAWER_ID_PREFIX = 'drawer-';
const SLIDE_MS = 200;

/**
 * Copy of the desktop rail for the drawer, with ids made unique so labels,
 * headings and form controls still point at their own copy.
 */
export const cloneRail = (rail) => {
    const copy = rail.cloneNode(true);
    const prefixIds = (value) => value.split(/\s+/).filter(Boolean).map((id) => `${DRAWER_ID_PREFIX}${id}`).join(' ');

    for (const element of copy.querySelectorAll('[id]')) {
        element.id = `${DRAWER_ID_PREFIX}${element.id}`;
    }

    for (const attribute of ['aria-labelledby', 'aria-controls', 'aria-describedby', 'for']) {
        for (const element of copy.querySelectorAll(`[${attribute}]`)) {
            element.setAttribute(attribute, prefixIds(element.getAttribute(attribute)));
        }
    }

    return copy;
};

export const initWorkspaceDrawer = ({ onOpen } = {}) => {
    const overlay = document.querySelector('[data-workspace-drawer]');
    const panel = overlay?.querySelector('[data-workspace-drawer-panel]');
    const rail = document.querySelector('[data-workspace-rail-dock] [data-workspace-rail]');
    const trigger = document.querySelector('[data-workspace-menu-open]');
    const page = document.querySelector('[data-workspace-page]');

    if (!overlay || !panel || !rail || !trigger) {
        return { open() {}, close() {}, isOpen: () => false };
    }

    const desktop = window.matchMedia('(min-width: 64rem)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let release = null;
    let hideTimer = null;

    const close = ({ restoreFocus = true } = {}) => {
        if (release === null) {
            return;
        }

        release({ restoreFocus });
        release = null;
        trigger.setAttribute('aria-expanded', 'false');
        overlay.dataset.state = 'closed';

        const finish = () => {
            overlay.hidden = true;
            panel.replaceChildren();
        };

        if (reducedMotion.matches) {
            finish();
        } else {
            hideTimer = window.setTimeout(finish, SLIDE_MS);
        }
    };

    const open = () => {
        if (release !== null || desktop.matches) {
            return;
        }

        window.clearTimeout(hideTimer);
        const copy = cloneRail(rail);
        const closeButton = copy.querySelector('[data-workspace-close]');
        if (closeButton) {
            closeButton.hidden = false;
            closeButton.addEventListener('click', () => close());
        }

        panel.replaceChildren(copy);
        overlay.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        window.requestAnimationFrame(() => {
            overlay.dataset.state = 'open';
        });

        release = trapDialog(panel, {
            onClose: () => close(),
            initialFocus: '[data-workspace-close]',
            fallbackFocus: trigger,
            inert: [page],
        });
        onOpen?.();
    };

    trigger.addEventListener('click', open);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });
    desktop.addEventListener('change', (event) => {
        if (event.matches && release !== null) {
            close({ restoreFocus: false });
            rail.querySelector('[aria-current="page"]')?.focus();
        }
    });

    return { open, close, isOpen: () => release !== null };
};
