import { trapDialog } from './dialog-focus.js';

const normalise = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();

/**
 * Every whitespace-separated term must appear in the label, section or keywords (#388).
 */
export const filterCommands = (commands, query = '') => {
    const terms = normalise(query).split(/\s+/).filter(Boolean);
    if (terms.length === 0) {
        return commands;
    }

    return commands.filter((command) => {
        const haystack = normalise(`${command.label} ${command.section} ${command.keywords}`);

        return terms.every((term) => haystack.includes(term));
    });
};

export const shortcutLabel = (platform = '') => (/Mac|iPhone|iPad|iPod/.test(platform) ? '⌘K' : 'Ctrl K');

export const moveIndex = (index, delta, length) => (length === 0 ? 0 : (index + delta + length) % length);

export const isPaletteShortcut = (event) => Boolean(event.metaKey || event.ctrlKey)
    && !event.altKey
    && String(event.key).toLowerCase() === 'k';

/**
 * Palette entries only ever point inside this app; anything else is ignored.
 */
export const sameOriginTarget = (url, origin) => {
    let target;
    try {
        target = new URL(String(url), origin);
    } catch {
        return null;
    }

    if (!['http:', 'https:'].includes(target.protocol) || target.origin !== origin) {
        return null;
    }

    return `${target.pathname}${target.search}${target.hash}`;
};

export const iconExportName = (name) => String(name)
    .split('-')
    .filter(Boolean)
    .map((part) => part[0].toUpperCase() + part.slice(1))
    .join('');

const readCommands = (node) => {
    try {
        const parsed = JSON.parse(node?.textContent || '[]');

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
};

export const initCommandPalette = ({ icons = {}, createElement = null, beforeOpen = null } = {}) => {
    const overlay = document.querySelector('[data-workspace-palette]');
    const dialog = overlay?.querySelector('[data-workspace-palette-dialog]');
    const input = overlay?.querySelector('[data-workspace-palette-input]');
    const list = overlay?.querySelector('[data-workspace-palette-list]');
    const empty = overlay?.querySelector('[data-workspace-palette-empty]');
    const page = document.querySelector('[data-workspace-page]');

    for (const hint of document.querySelectorAll('[data-workspace-shortcut]')) {
        hint.textContent = shortcutLabel(navigator.userAgentData?.platform ?? navigator.platform ?? '');
    }

    if (!overlay || !dialog || !(input instanceof HTMLInputElement) || !list) {
        return { open() {}, close() {} };
    }

    const commands = readCommands(overlay.querySelector('[data-workspace-palette-index]'));
    let results = commands;
    let activeIndex = 0;
    let release = null;

    const optionId = (index) => `workspace-palette-option-${index}`;

    const iconFor = (name) => {
        const node = icons[iconExportName(name)];
        if (!node || typeof createElement !== 'function') {
            return null;
        }

        const svg = createElement(node);
        svg.setAttribute('aria-hidden', 'true');

        return svg;
    };

    const syncActive = () => {
        for (const [index, option] of Array.from(list.children).entries()) {
            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
        }

        if (results.length > 0) {
            input.setAttribute('aria-activedescendant', optionId(activeIndex));
            document.getElementById(optionId(activeIndex))?.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const render = () => {
        results = filterCommands(commands, input.value);
        activeIndex = 0;
        list.replaceChildren(...results.map((command, index) => {
            const option = document.createElement('li');
            option.id = optionId(index);
            option.className = 'sg-palette-option';
            option.setAttribute('role', 'option');
            const icon = iconFor(command.icon);
            if (icon) {
                option.append(icon);
            }

            const label = document.createElement('span');
            label.className = 'sg-palette-option__label';
            label.textContent = command.label;

            const context = document.createElement('span');
            context.className = 'sg-palette-option__context';
            context.textContent = command.section;

            option.append(label, context);
            option.addEventListener('mousemove', () => {
                if (activeIndex !== index) {
                    activeIndex = index;
                    syncActive();
                }
            });
            option.addEventListener('click', () => run(command));

            return option;
        }));

        if (empty) {
            empty.hidden = results.length > 0;
        }

        syncActive();
    };

    const close = () => {
        if (release === null) {
            return;
        }

        release();
        release = null;
        overlay.hidden = true;
        input.value = '';
    };

    const run = (command) => {
        if (!command) {
            return;
        }

        const target = sameOriginTarget(command.url, window.location.origin);
        if (target === null) {
            return;
        }

        close();
        window.location.assign(target);
    };

    const open = () => {
        if (release !== null) {
            input.focus();

            return;
        }

        beforeOpen?.();
        overlay.hidden = false;
        input.value = '';
        render();
        release = trapDialog(dialog, { onClose: close, initialFocus: '[data-workspace-palette-input]', inert: [page] });
    };

    input.addEventListener('input', render);
    input.addEventListener('keydown', (event) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                activeIndex = moveIndex(activeIndex, 1, results.length);
                syncActive();
                break;
            case 'ArrowUp':
                event.preventDefault();
                activeIndex = moveIndex(activeIndex, -1, results.length);
                syncActive();
                break;
            case 'Home':
                event.preventDefault();
                activeIndex = 0;
                syncActive();
                break;
            case 'End':
                event.preventDefault();
                activeIndex = Math.max(results.length - 1, 0);
                syncActive();
                break;
            case 'Enter':
                event.preventDefault();
                run(results[activeIndex]);
                break;
            default:
                break;
        }
    });

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-workspace-palette-open]')) {
            event.preventDefault();
            open();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (isPaletteShortcut(event)) {
            event.preventDefault();
            open();
        }
    });

    return { open, close };
};
