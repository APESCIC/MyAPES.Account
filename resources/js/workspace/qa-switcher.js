const DESKTOP = '(min-width: 64rem)';

const pillFor = (flyout) => flyout.closest('[data-workspace-dock]')?.querySelector('[data-qa-pill]')
    ?? flyout.closest('[data-qa-dock]')?.querySelector('[data-qa-pill]')
    ?? null;

/**
 * The local QA role switcher: an amber pill that opens a popover of QA profiles.
 */
export const initQaSwitcher = () => {
    document.addEventListener('toggle', (event) => {
        const flyout = event.target;
        if (!(flyout instanceof HTMLElement) || !flyout.matches('[data-qa-flyout]')) {
            return;
        }

        const open = event.newState === 'open';
        pillFor(flyout)?.setAttribute('aria-expanded', String(open));
        if (open) {
            flyout.querySelector('[aria-current="true"]')?.focus();
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const flyout = document.querySelector('[data-qa-flyout]:popover-open');
        if (!flyout) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        flyout.hidePopover();
        pillFor(flyout)?.focus();
    }, true);

    const pending = document.querySelector('[data-qa-flyout][data-qa-autoopen]');
    if (pending && window.matchMedia(DESKTOP).matches) {
        pending.showPopover();
    }
};
