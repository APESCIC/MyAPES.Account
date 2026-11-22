/**
 * Notification click targets must stay on this origin (#793).
 *
 * @param {string} origin - window.location.origin in the page, or self.location.origin in the worker
 * @param {string|undefined|null} candidate - absolute or relative URL from push payload data
 * @returns {string|null} Same-origin URL to open, or null when the candidate is outside the origin
 */
export function resolveSameOriginNotificationUrl(origin, candidate) {
    if (typeof candidate !== 'string') {
        return null;
    }

    const trimmed = candidate.trim();

    if (trimmed === '') {
        return null;
    }

    try {
        const resolved = new URL(trimmed, origin);

        if (resolved.origin !== origin) {
            return null;
        }

        return `${resolved.pathname}${resolved.search}${resolved.hash}`;
    } catch {
        return null;
    }
}