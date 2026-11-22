const bellFor = (flyout) => flyout.closest('[data-workspace-dock]')?.querySelector('[data-notification-bell]')
    ?? document.querySelector('[data-notification-bell]');

/**
 * In-app notification bell popover (#801).
 */
export const initNotificationBell = () => {
    document.addEventListener('toggle', (event) => {
        const flyout = event.target;
        if (!(flyout instanceof HTMLElement) || !flyout.matches('[data-notification-flyout]')) {
            return;
        }

        const open = event.newState === 'open';
        bellFor(flyout)?.setAttribute('aria-expanded', String(open));
        if (open) {
            flyout.querySelector('.sg-notification-list__link, .sg-btn')?.focus();
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const flyout = document.querySelector('[data-notification-flyout]:popover-open');
        if (!flyout) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        flyout.hidePopover();
        bellFor(flyout)?.focus();
    }, true);
};