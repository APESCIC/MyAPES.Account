import {
    ArrowRight,
    BadgeCheck,
    Briefcase,
    BriefcaseBusiness,
    Building2,
    ChevronRight,
    Circle,
    CircleHelp,
    CirclePause,
    CircleCheckBig,
    CirclePlus,
    CircleUserRound,
    ClipboardList,
    Compass,
    createElement,
    createIcons,
    ExternalLink,
    Github,
    Heart,
    HeartPulse,
    House,
    Inbox,
    LayoutDashboard,
    Leaf,
    LogIn,
    LogOut,
    Mail,
    Menu,
    MessagesSquare,
    Moon,
    PawPrint,
    Plus,
    Puzzle,
    ScrollText,
    Search,
    Settings,
    ShieldCheck,
    Sprout,
    Sun,
    Ticket,
    UserPlus,
    UserRound,
    X,
} from 'lucide';
import { initChangeLog } from './change-log.js';
import { initPasskeys } from './passkeys.js';
import { initCommandPalette } from './workspace/command-palette.js';
import { initWorkspaceDrawer } from './workspace/drawer.js';

const icons = {
    ArrowRight,
    BadgeCheck,
    Briefcase,
    BriefcaseBusiness,
    Building2,
    ChevronRight,
    Circle,
    CircleHelp,
    CirclePause,
    CircleCheckBig,
    CirclePlus,
    CircleUserRound,
    ClipboardList,
    Compass,
    ExternalLink,
    Github,
    Heart,
    HeartPulse,
    House,
    Inbox,
    LayoutDashboard,
    Leaf,
    LogIn,
    LogOut,
    Mail,
    Menu,
    MessagesSquare,
    Moon,
    PawPrint,
    Plus,
    Puzzle,
    ScrollText,
    Search,
    Settings,
    ShieldCheck,
    Sprout,
    Sun,
    Ticket,
    UserPlus,
    UserRound,
    X,
};

const themeStorageKey = 'myapes-theme';
const themeColor = document.querySelector('meta[name="theme-color"]');

const applyTheme = (theme, persist = false) => {
    const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
    const isDark = normalizedTheme === 'dark';

    document.documentElement.dataset.theme = normalizedTheme;
    document.documentElement.style.colorScheme = normalizedTheme;
    themeColor?.setAttribute('content', isDark ? '#0b1a1f' : '#d2f4fa');

    for (const toggle of document.querySelectorAll('[data-theme-toggle]')) {
        toggle.setAttribute('aria-pressed', String(isDark));
        const label = isDark ? toggle.dataset.labelLight : toggle.dataset.labelDark;
        if (label) {
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
        }
    }

    if (persist) {
        try {
            localStorage.setItem(themeStorageKey, normalizedTheme);
        } catch {
            // The selected theme still applies for the current page when storage is unavailable.
        }
    }
};

createIcons({ icons });

applyTheme(document.documentElement.dataset.theme);
initChangeLog();
initPasskeys();

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-theme-toggle]')) {
        applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark', true);
    }
});

const drawer = initWorkspaceDrawer({
    onOpen: () => applyTheme(document.documentElement.dataset.theme),
});
initCommandPalette({
    icons,
    createElement,
    beforeOpen: () => drawer.close({ restoreFocus: false }),
});

const mascotStorageKey = 'myapes-mascot-dismissed-v2';
const mascotDock = document.querySelector('[data-mascot-dock]');
const mascotToggle = document.querySelector('[data-mascot-toggle]');
const mascotBubble = document.querySelector('[data-mascot-bubble]');
const mascotDismiss = document.querySelector('[data-mascot-dismiss]');

const readDismissedMascotRoutes = () => {
    try {
        const stored = JSON.parse(localStorage.getItem(mascotStorageKey) ?? '[]');

        return Array.isArray(stored) ? stored.filter((value) => typeof value === 'string') : [];
    } catch {
        return [];
    }
};

const hideMascotDock = () => {
    if (!(mascotDock instanceof HTMLElement)) {
        return;
    }

    mascotDock.hidden = true;
    document.body.classList.remove('has-mascot-dock');
};

const setMascotDockExpanded = (expanded) => {
    if (!(mascotDock instanceof HTMLElement)) {
        return;
    }

    mascotDock.classList.toggle('mascot-dock--collapsed', !expanded);

    if (mascotToggle instanceof HTMLElement) {
        mascotToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        mascotToggle.setAttribute(
            'aria-label',
            expanded ? 'Hide tip from Spike' : 'Show tip from Spike',
        );
    }

    if (mascotBubble instanceof HTMLElement) {
        mascotBubble.hidden = !expanded;
    }
};

if (mascotDock instanceof HTMLElement) {
    const routeName = mascotDock.dataset.mascotRoute ?? '';

    if (routeName !== '' && readDismissedMascotRoutes().includes(routeName)) {
        hideMascotDock();
    }

    mascotToggle?.addEventListener('click', () => {
        const expanded = mascotToggle.getAttribute('aria-expanded') === 'true';

        setMascotDockExpanded(!expanded);
    });

    mascotDismiss?.addEventListener('click', () => {
        if (routeName === '') {
            return;
        }

        const dismissed = new Set(readDismissedMascotRoutes());
        dismissed.add(routeName);

        try {
            localStorage.setItem(mascotStorageKey, JSON.stringify([...dismissed]));
        } catch {
            // The tip still hides for this page when storage is unavailable.
        }

        hideMascotDock();
    });
}
