// Spike's guided tour (#489), built on driver.js. The server renders the
// steps into script[data-guided-tour] on the dashboard; each step lists
// targets in order and the first visible one is highlighted, so a step falls
// back to the mobile menu button when the desktop menu is hidden.

export const isVisible = (element, view = globalThis) => {
    if (!element || typeof element.getBoundingClientRect !== 'function') {
        return false;
    }

    const box = element.getBoundingClientRect();
    if (box.width <= 0 || box.height <= 0) {
        return false;
    }

    const style = view.getComputedStyle ? view.getComputedStyle(element) : null;

    return !style || (style.visibility !== 'hidden' && style.display !== 'none');
};

export const firstVisibleTarget = (targets, root = document, visible = isVisible) => {
    for (const selector of targets ?? []) {
        for (const element of root.querySelectorAll(selector)) {
            if (visible(element)) {
                return element;
            }
        }
    }

    return null;
};

// Character-code map avoids replaceAll-based sanitizers that Semgrep flags as brittle.
const HTML_ESCAPES = Object.freeze({
    34: '&quot;',
    38: '&amp;',
    60: '&lt;',
    62: '&gt;',
});

const escapeHtml = (value) => {
    let escaped = '';
    for (const char of String(value)) {
        escaped += HTML_ESCAPES[char.charCodeAt(0)] ?? char;
    }

    return escaped;
};

export const popoverDescription = (body, image) => (image
    ? `<span class="tour-spike"><img src="${escapeHtml(image)}" alt="" width="56" height="56"><span>${escapeHtml(body)}</span></span>`
    : escapeHtml(body));

export const buildSteps = (config, root = document, visible = isVisible) => (config.steps ?? []).map((step) => {
    const initial = firstVisibleTarget(step.targets, root, visible);

    return {
        // Re-resolved when the step shows, in case the window was resized since the tour began.
        ...(initial ? { element: () => firstVisibleTarget(step.targets, root, visible) ?? initial } : {}),
        popover: {
            title: escapeHtml(step.title),
            description: popoverDescription(step.body, config.image),
        },
    };
});

export const readTourConfig = (root = document) => {
    const node = root.querySelector('script[data-guided-tour]');
    if (!node) {
        return null;
    }

    try {
        const config = JSON.parse(node.textContent ?? '');

        return config && Array.isArray(config.steps) && config.steps.length > 0 ? config : null;
    } catch {
        return null;
    }
};

export const saveTourResult = (config, skipped, fetchFn = globalThis.fetch) => {
    if (typeof fetchFn !== 'function' || !config.completeUrl) {
        return Promise.resolve();
    }

    return fetchFn(config.completeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': config.csrfToken ?? '',
        },
        body: JSON.stringify({ skipped }),
    }).catch(() => {});
};

const clearRestartParam = (config, location, history) => {
    if (!config.restartParam || !location || !history?.replaceState) {
        return;
    }

    const url = new URL(location.href);
    if (url.searchParams.has(config.restartParam)) {
        url.searchParams.delete(config.restartParam);
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    }
};

export const initGuidedTour = async ({ root = document, loadDriver = () => import('driver.js') } = {}) => {
    const config = readTourConfig(root);
    if (!config) {
        return null;
    }

    clearRestartParam(config, globalThis.location, globalThis.history);

    if (!config.autoStart) {
        return null;
    }

    const [{ driver }] = await Promise.all([loadDriver(), import('driver.js/dist/driver.css')]);
    const steps = buildSteps(config, root);
    let finished = false;

    const tour = driver({
        steps,
        showProgress: true,
        allowClose: true,
        overlayClickBehavior: 'close',
        smoothScroll: true,
        popoverClass: 'myapes-tour',
        nextBtnText: config.labels?.next,
        prevBtnText: config.labels?.previous,
        doneBtnText: config.labels?.done,
        progressText: config.labels?.progress,
        onDestroyStarted: () => {
            if (!finished) {
                finished = true;
                saveTourResult(config, !tour.isLastStep());
            }
            tour.destroy();
        },
        onPopoverRender: (popover) => {
            popover.closeButton?.setAttribute('aria-label', config.labels?.close ?? '');
            popover.closeButton?.setAttribute('title', config.labels?.close ?? '');
        },
    });

    tour.drive();

    return tour;
};
