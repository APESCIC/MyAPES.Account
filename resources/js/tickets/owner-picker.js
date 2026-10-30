export const OWNER_SEARCH_MIN = 2;

export const shouldSearchOwners = (term) => term.trim().length >= OWNER_SEARCH_MIN;

const replaceMatches = (select, matches) => {
    const current = select.querySelector('[data-owner-current]');
    select.replaceChildren(...(current ? [current] : []));

    for (const match of matches) {
        const option = document.createElement('option');
        option.value = String(match.id);
        option.textContent = match.label;
        select.append(option);
    }
};

export const initOwnerPicker = (root = document) => {
    for (const picker of root.querySelectorAll('[data-owner-picker]')) {
        const input = picker.querySelector('[data-owner-search]');
        const select = picker.querySelector('[data-owner-results]');
        const status = picker.querySelector('[data-owner-status]');
        const endpoint = picker.dataset.ownerEndpoint;
        if (!(input instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement) || !endpoint) {
            continue;
        }

        let timer;
        let controller;

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(async () => {
                const term = input.value;
                if (!shouldSearchOwners(term)) {
                    replaceMatches(select, []);
                    if (status) status.textContent = '';
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

                    const { data } = await response.json();
                    replaceMatches(select, data);
                    if (status) status.textContent = data.length === 0 ? picker.dataset.ownerEmpty ?? '' : '';
                } catch (error) {
                    if (error?.name !== 'AbortError') {
                        throw error;
                    }
                }
            }, 250);
        });
    }
};
