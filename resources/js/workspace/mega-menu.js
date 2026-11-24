import { focusableWithin } from './dialog-focus.js';

const openMega = (root, instance) => {
    const { trigger, panel } = instance;

    for (const other of root.querySelectorAll('[data-workspace-mega]')) {
        if (other === instance.container) {
            continue;
        }

        const otherTrigger = other.querySelector('[data-workspace-mega-trigger]');
        const otherPanel = other.querySelector('[data-workspace-mega-panel]');
        otherPanel?.setAttribute('hidden', '');
        otherTrigger?.setAttribute('aria-expanded', 'false');
        other.classList.remove('is-open');
    }

    panel.removeAttribute('hidden');
    panel.removeAttribute('data-align');
    trigger.setAttribute('aria-expanded', 'true');
    instance.container.classList.add('is-open');

    const triggerRect = trigger.getBoundingClientRect();
    const panelRect = panel.getBoundingClientRect();
    const overflowRight = panelRect.right > window.innerWidth - 12;
    const fitsLeftAligned = triggerRect.left + panelRect.width <= window.innerWidth - 12;
    if (overflowRight && !fitsLeftAligned) {
        panel.setAttribute('data-align', 'end');
    }
};

const closeMega = (instance) => {
    instance.panel.setAttribute('hidden', '');
    instance.panel.removeAttribute('data-align');
    instance.trigger.setAttribute('aria-expanded', 'false');
    instance.container.classList.remove('is-open');
};

const closeAllMega = (root) => {
    for (const container of root.querySelectorAll('[data-workspace-mega]')) {
        const trigger = container.querySelector('[data-workspace-mega-trigger]');
        const panel = container.querySelector('[data-workspace-mega-panel]');
        if (!trigger || !panel) {
            continue;
        }

        panel.setAttribute('hidden', '');
        panel.removeAttribute('data-align');
        trigger.setAttribute('aria-expanded', 'false');
        container.classList.remove('is-open');
    }
};

export const initWorkspaceMegaMenu = () => {
    const root = document.querySelector('[data-workspace-mega-root]');
    if (!root) {
        return;
    }

    const instances = [...root.querySelectorAll('[data-workspace-mega]')].map((container) => ({
        container,
        trigger: container.querySelector('[data-workspace-mega-trigger]'),
        panel: container.querySelector('[data-workspace-mega-panel]'),
        links: () => [...container.querySelectorAll('[data-workspace-mega-link]')],
    })).filter((entry) => entry.trigger instanceof HTMLElement && entry.panel instanceof HTMLElement);

    for (const instance of instances) {
        instance.trigger.addEventListener('click', () => {
            const isOpen = instance.container.classList.contains('is-open');
            if (isOpen) {
                closeMega(instance);
                instance.trigger.focus();

                return;
            }

            openMega(root, instance);
            instance.links()[0]?.focus();
        });

        instance.trigger.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || (event.key === 'Enter' && !instance.container.classList.contains('is-open'))) {
                event.preventDefault();
                openMega(root, instance);
                instance.links()[0]?.focus();
            }
        });
    }

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.closest('[data-workspace-mega]')) {
            return;
        }

        closeAllMega(root);
    });

    document.addEventListener('keydown', (event) => {
        const openContainer = root.querySelector('[data-workspace-mega].is-open');
        if (!openContainer) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            const instance = instances.find((entry) => entry.container === openContainer);
            if (instance) {
                closeMega(instance);
                instance.trigger.focus();
            }

            return;
        }

        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }

        const instance = instances.find((entry) => entry.container === openContainer);
        if (!instance) {
            return;
        }

        const links = instance.links();
        if (links.length === 0) {
            return;
        }

        const active = document.activeElement;
        const index = links.indexOf(active);
        let next = 0;

        if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = links.length - 1;
        } else if (event.key === 'ArrowDown') {
            next = index === -1 ? 0 : (index + 1) % links.length;
        } else {
            next = index <= 0 ? links.length - 1 : index - 1;
        }

        event.preventDefault();
        links[next].focus();
    });
};

export const initWorkspaceUserMenu = () => {
    const menus = document.querySelectorAll('[data-workspace-user-menu]');

    for (const menu of menus) {
        const trigger = menu.querySelector('[data-workspace-user-menu-trigger]');
        const panel = menu.querySelector('[data-workspace-user-menu-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
            continue;
        }

        const close = () => {
            panel.setAttribute('hidden', '');
            trigger.setAttribute('aria-expanded', 'false');
            menu.classList.remove('is-open');
        };

        const open = () => {
            panel.removeAttribute('hidden');
            trigger.setAttribute('aria-expanded', 'true');
            menu.classList.add('is-open');
            // Prefer the first menuitem so QA / theme controls do not steal focus (#1046).
            const firstItem = panel.querySelector('[role="menuitem"]');
            if (firstItem instanceof HTMLElement) {
                firstItem.focus();
            } else {
                focusableWithin(panel)[0]?.focus();
            }
        };

        trigger.addEventListener('click', () => {
            if (menu.classList.contains('is-open')) {
                close();
            } else {
                open();
            }
        });

        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                trigger.focus();
            }
        });

        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Element) || !menu.classList.contains('is-open')) {
                return;
            }

            if (event.target.closest('[data-workspace-user-menu]')) {
                return;
            }

            close();
        });
    }
};