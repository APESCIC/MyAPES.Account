export const USER_SEARCH_MIN = 2;

export const shouldSearchUsers = (term) => term.trim().length >= USER_SEARCH_MIN;

const setPanelVisibility = (root, mode) => {
    for (const panel of root.querySelectorAll('[data-filter-panel]')) {
        if (!(panel instanceof HTMLElement)) {
            continue;
        }

        panel.classList.toggle('hidden', panel.dataset.filterPanel !== mode);
    }
};

const replaceUserOptions = (select, candidates, selectedIds) => {
    const kept = [];

    for (const id of selectedIds) {
        const existing = select.querySelector(`option[value="${CSS.escape(String(id))}"]`);
        if (existing instanceof HTMLOptionElement) {
            existing.selected = true;
            kept.push(existing);
        }
    }

    select.replaceChildren(...kept);

    for (const candidate of candidates) {
        if (selectedIds.has(String(candidate.id))) {
            continue;
        }

        const option = document.createElement('option');
        option.value = String(candidate.id);
        option.textContent = candidate.label
            ? `${candidate.label}${candidate.detail ? ` (${candidate.detail})` : ''}`
            : String(candidate.id);
        select.append(option);
    }
};

export const initStaffMessageFilter = (root = document) => {
    for (const modeSelect of root.querySelectorAll('[data-staff-message-filter]')) {
        if (!(modeSelect instanceof HTMLSelectElement)) {
            continue;
        }

        const fieldset = modeSelect.closest('fieldset') ?? modeSelect.parentElement;
        if (!(fieldset instanceof HTMLElement)) {
            continue;
        }

        const sync = () => setPanelVisibility(fieldset, modeSelect.value);
        modeSelect.addEventListener('change', sync);
        sync();
    }

    for (const select of root.querySelectorAll('[data-staff-message-user-picker]')) {
        if (!(select instanceof HTMLSelectElement)) {
            continue;
        }

        const endpoint = select.dataset.endpoint;
        if (!endpoint) {
            continue;
        }

        const panel = select.closest('[data-filter-panel="users"]');
        if (!(panel instanceof HTMLElement)) {
            continue;
        }

        let search = panel.querySelector('[data-staff-message-user-search]');
        if (!(search instanceof HTMLInputElement)) {
            search = document.createElement('input');
            search.type = 'search';
            search.setAttribute('data-staff-message-user-search', '');
            search.setAttribute('placeholder', panel.querySelector('.muted')?.textContent?.trim() ?? '');
            search.setAttribute('autocomplete', 'off');
            panel.insertBefore(search, select);
        }

        let timer;
        let controller;

        search.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(async () => {
                const term = search.value;
                const selectedIds = new Set(
                    [...select.selectedOptions].map((option) => option.value),
                );

                if (!shouldSearchUsers(term)) {
                    replaceUserOptions(select, [], selectedIds);
                    return;
                }

                controller?.abort();
                controller = new AbortController();

                try {
                    const url = new URL(endpoint, window.location.origin);
                    url.searchParams.set('q', term);
                    const response = await fetch(url, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();
                    replaceUserOptions(select, payload.candidates ?? [], selectedIds);
                } catch (error) {
                    if (error?.name !== 'AbortError') {
                        throw error;
                    }
                }
            }, 250);
        });
    }
};
