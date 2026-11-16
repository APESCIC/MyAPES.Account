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

/**
 * Flatten static commands and record groups into one keyboard-navigable list (#528).
 *
 * @param {Array<{id: string, label: string, section?: string, icon?: string}>} commands
 * @param {Array<{type: string, label: string, hits: Array<object>}>} recordGroups
 * @returns {Array<{kind: 'command'|'record'|'heading', id?: string, label: string, section?: string, icon?: string, url?: string, type?: string, selectable: boolean}>}
 */
export const mergePaletteResults = (commands = [], recordGroups = []) => {
    const rows = commands.map((command) => ({
        kind: 'command',
        id: command.id,
        label: command.label,
        section: command.section,
        icon: command.icon,
        selectable: true,
    }));

    for (const group of recordGroups) {
        if (!Array.isArray(group?.hits) || group.hits.length === 0) {
            continue;
        }

        rows.push({
            kind: 'heading',
            label: group.label || group.type || 'Records',
            type: group.type,
            selectable: false,
        });

        for (const hit of group.hits) {
            rows.push({
                kind: 'record',
                id: `record:${group.type}:${hit.id}`,
                label: hit.label,
                section: hit.subtitle || hit.reference || group.label || '',
                url: hit.url,
                status: hit.status,
                type: group.type,
                selectable: true,
            });
        }
    }

    return rows;
};

export const selectableIndexes = (rows) => rows
    .map((row, index) => (row.selectable ? index : -1))
    .filter((index) => index >= 0);

export const moveSelectableIndex = (rows, activeIndex, delta) => {
    const indexes = selectableIndexes(rows);
    if (indexes.length === 0) {
        return 0;
    }

    const currentSlot = indexes.indexOf(activeIndex);
    const from = currentSlot === -1 ? (delta > 0 ? -1 : 0) : currentSlot;
    const nextSlot = (from + delta + indexes.length) % indexes.length;

    return indexes[nextSlot];
};

export const shortcutLabel = (platform = '') => (/Mac|iPhone|iPad|iPod/.test(platform) ? '⌘K' : 'Ctrl K');

export const moveIndex = (index, delta, length) => (length === 0 ? 0 : (index + delta + length) % length);

export const isPaletteShortcut = (event) => Boolean(event.metaKey || event.ctrlKey)
    && !event.altKey
    && String(event.key).toLowerCase() === 'k';

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

const firstSelectableIndex = (rows) => {
    const indexes = selectableIndexes(rows);

    return indexes[0] ?? 0;
};

export const initCommandPalette = ({ icons = {}, createElement = null, beforeOpen = null, fetchImpl = null } = {}) => {
    const overlay = document.querySelector('[data-workspace-palette]');
    const dialog = overlay?.querySelector('[data-workspace-palette-dialog]');
    const input = overlay?.querySelector('[data-workspace-palette-input]');
    const list = overlay?.querySelector('[data-workspace-palette-list]');
    const empty = overlay?.querySelector('[data-workspace-palette-empty]');
    const page = document.querySelector('[data-workspace-page]');
    const recordsUrl = overlay?.dataset.workspaceRecordSearchUrl || '';
    const fetchFn = fetchImpl || (typeof fetch === 'function' ? fetch.bind(globalThis) : null);

    for (const hint of document.querySelectorAll('[data-workspace-shortcut]')) {
        hint.textContent = shortcutLabel(navigator.userAgentData?.platform ?? navigator.platform ?? '');
    }

    if (!overlay || !dialog || !(input instanceof HTMLInputElement) || !list) {
        return { open() {}, close() {} };
    }

    const commands = readCommands(overlay.querySelector('[data-workspace-palette-index]'));
    let recordGroups = [];
    let results = mergePaletteResults(commands, []);
    let activeIndex = firstSelectableIndex(results);
    let release = null;
    let debounceTimer = null;
    let requestToken = 0;

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
            if (option.getAttribute('role') === 'presentation') {
                continue;
            }

            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
        }

        const active = results[activeIndex];
        if (active?.selectable) {
            input.setAttribute('aria-activedescendant', optionId(activeIndex));
            document.getElementById(optionId(activeIndex))?.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const paint = () => {
        list.replaceChildren(...results.map((row, index) => {
            if (row.kind === 'heading') {
                const heading = document.createElement('li');
                heading.className = 'sg-palette__section';
                heading.setAttribute('role', 'presentation');
                heading.textContent = row.label;

                return heading;
            }

            const option = document.createElement('li');
            option.id = optionId(index);
            option.className = 'sg-palette-option';
            option.setAttribute('role', 'option');
            const icon = iconFor(row.icon || (row.kind === 'record' ? 'search' : 'circle'));
            if (icon) {
                option.append(icon);
            }

            const label = document.createElement('span');
            label.className = 'sg-palette-option__label';
            label.textContent = row.label;

            const context = document.createElement('span');
            context.className = 'sg-palette-option__context';
            context.textContent = row.section || '';

            option.append(label, context);
            option.addEventListener('mousemove', () => {
                if (activeIndex !== index) {
                    activeIndex = index;
                    syncActive();
                }
            });
            option.addEventListener('click', () => run(row));

            return option;
        }));

        if (empty) {
            empty.hidden = selectableIndexes(results).length > 0;
        }

        syncActive();
    };

    const renderStatic = () => {
        results = mergePaletteResults(filterCommands(commands, input.value), recordGroups);
        activeIndex = firstSelectableIndex(results);
        paint();
    };

    const loadRecords = async (query) => {
        if (!recordsUrl || !fetchFn || query.trim().length < 2) {
            recordGroups = [];
            renderStatic();

            return;
        }

        const token = ++requestToken;
        try {
            const url = new URL(recordsUrl, window.location.origin);
            url.searchParams.set('q', query.trim());
            const response = await fetchFn(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`record search failed: ${response.status}`);
            }

            const payload = await response.json();
            if (token !== requestToken) {
                return;
            }

            recordGroups = Array.isArray(payload?.groups) ? payload.groups : [];
        } catch {
            if (token !== requestToken) {
                return;
            }

            recordGroups = [];
        }

        renderStatic();
    };

    const scheduleRecords = () => {
        if (debounceTimer !== null) {
            window.clearTimeout(debounceTimer);
        }

        const query = input.value;
        renderStatic();

        debounceTimer = window.setTimeout(() => {
            debounceTimer = null;
            void loadRecords(query);
        }, 250);
    };

    const close = () => {
        if (release === null) {
            return;
        }

        if (debounceTimer !== null) {
            window.clearTimeout(debounceTimer);
            debounceTimer = null;
        }

        requestToken += 1;
        release();
        release = null;
        overlay.hidden = true;
        input.value = '';
        recordGroups = [];
        results = mergePaletteResults(commands, []);
    };

    const run = (row) => {
        if (!row?.selectable) {
            return;
        }

        if (row.kind === 'record') {
            if (!row.url) {
                return;
            }

            close();
            window.location.assign(row.url);

            return;
        }

        const link = Array.from(overlay.querySelectorAll('[data-workspace-palette-links] a[data-command-id]'))
            .find((anchor) => anchor.dataset.commandId === row.id);
        if (!link) {
            return;
        }

        close();
        link.click();
    };

    const open = () => {
        if (release !== null) {
            input.focus();

            return;
        }

        beforeOpen?.();
        overlay.hidden = false;
        input.value = '';
        recordGroups = [];
        renderStatic();
        release = trapDialog(dialog, { onClose: close, initialFocus: '[data-workspace-palette-input]', inert: [page] });
    };

    input.addEventListener('input', scheduleRecords);
    input.addEventListener('keydown', (event) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                activeIndex = moveSelectableIndex(results, activeIndex, 1);
                syncActive();
                break;
            case 'ArrowUp':
                event.preventDefault();
                activeIndex = moveSelectableIndex(results, activeIndex, -1);
                syncActive();
                break;
            case 'Home': {
                event.preventDefault();
                const indexes = selectableIndexes(results);
                activeIndex = indexes[0] ?? 0;
                syncActive();
                break;
            }
            case 'End': {
                event.preventDefault();
                const indexes = selectableIndexes(results);
                activeIndex = indexes[indexes.length - 1] ?? 0;
                syncActive();
                break;
            }
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
