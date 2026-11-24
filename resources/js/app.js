import {
    ArrowRight,
    BadgeCheck,
    Bell,
    BookOpen,
    Briefcase,
    BriefcaseBusiness,
    Building2,
    Calendar,
    ChevronDown,
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
    FileText,
    FlaskConical,
    Github,
    Heart,
    HeartPulse,
    House,
    Image,
    Inbox,
    LayoutDashboard,
    Leaf,
    LifeBuoy,
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
    ShieldAlert,
    ShieldCheck,
    Sprout,
    Sun,
    Ticket,
    Trash2,
    Upload,
    UserPlus,
    UserRound,
    Video,
    X,
} from 'lucide';
import { initBotProtection } from './bot-protection.js';
import { initChangeLog } from './change-log.js';
import { initPasskeys } from './passkeys.js';
import { initPushServiceWorker } from './push/register.js';
import { initPushSoftPrompt } from './push/soft-prompt.js';
import { initStaffMessageFilter } from './staff/message-filter.js';
import { initOwnerPicker } from './tickets/owner-picker.js';
import { initClientsDirectory } from './workspace/clients-directory.js';
import { initCommandPalette } from './workspace/command-palette.js';
import { initWorkspaceDrawer } from './workspace/drawer.js';
import { initWorkspaceMegaMenu, initWorkspaceUserMenu } from './workspace/mega-menu.js';
import { initFileUploads } from './workspace/file-upload.js';
import { initFormActions } from './workspace/form-actions.js';
import { initGuidedTour } from './workspace/guided-tour.js';
import { initNotificationBell } from './workspace/notification-bell.js';
import { initQaSwitcher } from './workspace/qa-switcher.js';
import { initRichTextEditors } from './workspace/rich-text-editor.js';
import { applyAppearance, initThemeSwitcher, readAppearanceFromDocument } from './workspace/theme-switcher.js';
import { initToasts } from './workspace/toasts.js';

const icons = {
    ArrowRight,
    BadgeCheck,
    Bell,
    BookOpen,
    Briefcase,
    BriefcaseBusiness,
    Building2,
    Calendar,
    ChevronDown,
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
    FileText,
    FlaskConical,
    Github,
    Heart,
    HeartPulse,
    House,
    Image,
    Inbox,
    LayoutDashboard,
    Leaf,
    LifeBuoy,
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
    ShieldAlert,
    ShieldCheck,
    Sprout,
    Sun,
    Ticket,
    Trash2,
    Upload,
    UserPlus,
    UserRound,
    Video,
    X,
};

createIcons({ icons });

applyAppearance(readAppearanceFromDocument());
initThemeSwitcher();
initChangeLog();
initBotProtection();
initPasskeys();
initPushServiceWorker();
initPushSoftPrompt();
initToasts();
initOwnerPicker();
initStaffMessageFilter();
initFormActions();
initFileUploads();
initRichTextEditors();

const drawer = initWorkspaceDrawer({
    onOpen: () => applyAppearance(readAppearanceFromDocument()),
});
initWorkspaceMegaMenu();
initWorkspaceUserMenu();
initCommandPalette({
    icons,
    createElement,
    beforeOpen: () => drawer.close({ restoreFocus: false }),
});
initClientsDirectory();
initGuidedTour();
initNotificationBell();
initQaSwitcher();

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
