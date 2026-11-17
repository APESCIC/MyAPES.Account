const normalize = (value) => String(value ?? '').trim().toLocaleLowerCase();

const matchesFilter = (release, filter) => {
    if (filter === 'all') {
        return true;
    }

    if (filter === 'current') {
        return release.current === true;
    }

    if (filter === 'beta') {
        return release.channel === 'beta';
    }

    return release.categories.includes(filter) || release.audiences.includes(filter);
};

export const matchesRelease = (release, query = '', filter = 'all') => {
    const normalizedQuery = normalize(query);
    const normalizedFilter = normalize(filter) || 'all';
    const matchesQuery = normalizedQuery === ''
        || normalize(release.searchText).includes(normalizedQuery);

    return matchesQuery && matchesFilter(release, normalizedFilter);
};

export const filterReleases = (releases, query = '', filter = 'all') => releases.filter(
    (release) => matchesRelease(release, query, filter),
);

const RELEASE_HASH_PATTERN = /^release-v[\d]+(?:-[\d]+)*$/i;

export const hashToReleaseId = (hash = '') => {
    const trimmed = String(hash ?? '').trim();

    if (trimmed === '' || trimmed === '#') {
        return null;
    }

    const id = trimmed.startsWith('#') ? trimmed.slice(1) : trimmed;

    return RELEASE_HASH_PATTERN.test(id) ? id.toLocaleLowerCase() : null;
};

const prefersReducedMotion = () => (
    typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true
);

const tokens = (value) => normalize(value).split(/\s+/).filter(Boolean);

const releaseFromElement = (element) => ({
    element,
    version: element.dataset.version ?? '',
    current: element.dataset.current === 'true',
    channel: normalize(element.dataset.channel),
    categories: tokens(element.dataset.categories),
    audiences: tokens(element.dataset.audiences),
    searchText: element.textContent ?? '',
});

export const initChangeLog = (root = document.querySelector('[data-change-log]')) => {
    if (! root) {
        return;
    }

    const controls = root.querySelector('[data-change-log-controls]');
    const search = root.querySelector('[data-change-log-search]');
    const filterButtons = [...root.querySelectorAll('[data-change-log-filter]')];
    const releaseRecords = [...root.querySelectorAll('[data-release-record]')].map(releaseFromElement);
    const status = root.querySelector('[data-change-log-status]');
    const emptyState = root.querySelector('[data-change-log-empty]');
    const expandButton = root.querySelector('[data-change-log-expand]');
    const collapseButton = root.querySelector('[data-change-log-collapse]');
    let activeFilter = 'all';
    let suppressHashUpdate = false;

    const setFilterToAll = () => {
        activeFilter = 'all';

        for (const candidate of filterButtons) {
            const value = candidate.dataset.changeLogFilter ?? 'all';
            candidate.setAttribute('aria-pressed', String(value === 'all'));
        }
    };

    const ensureReleaseVisible = (release) => {
        if (! release.element.hidden) {
            return true;
        }

        setFilterToAll();
        render();

        if (! release.element.hidden) {
            return true;
        }

        if (search) {
            search.value = '';
        }

        render();

        return ! release.element.hidden;
    };

    const scrollReleaseIntoView = (element) => {
        element.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'start',
        });
    };

    const setHashWithoutScroll = (id) => {
        if (! id) {
            return;
        }

        const url = new URL(window.location.href);
        url.hash = id;
        window.history.replaceState(null, '', url);
    };

    const openReleaseFromHash = ({ scroll = false } = {}) => {
        const id = hashToReleaseId(window.location.hash);

        if (! id) {
            return;
        }

        const release = releaseRecords.find((record) => record.element.id === id);

        if (! release) {
            return;
        }

        if (! ensureReleaseVisible(release)) {
            return;
        }

        const details = release.element.querySelector('[data-release-details]');

        if (details instanceof HTMLDetailsElement) {
            suppressHashUpdate = true;
            details.open = true;
            suppressHashUpdate = false;
        }

        if (scroll) {
            scrollReleaseIntoView(release.element);
        }
    };

    const render = () => {
        const visible = new Set(filterReleases(releaseRecords, search?.value ?? '', activeFilter));

        for (const release of releaseRecords) {
            release.element.hidden = ! visible.has(release);
        }

        if (status) {
            const noun = visible.size === 1 ? 'release' : 'releases';
            status.textContent = `Showing ${visible.size} of ${releaseRecords.length} ${noun}`;
        }

        if (emptyState) {
            emptyState.hidden = visible.size !== 0;
        }
    };

    controls?.removeAttribute('hidden');
    search?.addEventListener('input', render);

    for (const button of filterButtons) {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.changeLogFilter ?? 'all';

            for (const candidate of filterButtons) {
                candidate.setAttribute('aria-pressed', String(candidate === button));
            }

            render();
        });
    }

    const setVisibleDetailsOpen = (open) => {
        for (const release of releaseRecords) {
            if (release.element.hidden) {
                continue;
            }

            const details = release.element.querySelector('[data-release-details]');
            if (details instanceof HTMLDetailsElement) {
                details.open = open;
            }
        }
    };

    expandButton?.addEventListener('click', () => setVisibleDetailsOpen(true));
    collapseButton?.addEventListener('click', () => setVisibleDetailsOpen(false));

    for (const release of releaseRecords) {
        const details = release.element.querySelector('[data-release-details]');

        if (! (details instanceof HTMLDetailsElement)) {
            continue;
        }

        details.addEventListener('toggle', () => {
            if (suppressHashUpdate || ! details.open) {
                return;
            }

            const { id } = release.element;

            if (id) {
                setHashWithoutScroll(id);
            }
        });
    }

    window.addEventListener('hashchange', () => {
        openReleaseFromHash({ scroll: true });
    });

    render();
    openReleaseFromHash({ scroll: true });
};
