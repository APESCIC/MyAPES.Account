# Workspace shell

The workspace shell (0.44.0, epic #382) is the frame around every page: a floating frosted-glass rail on the left, a content header with a breadcrumb and search, and a Ctrl K command palette. It copies the APES Newsroom admin menu (`/admin/moderation`, `APESCIC/APES.Newsroom` main @2e5693c) in MyAPES logo colours. It replaced the old `aside#site-sidebar` and the planned option-5 mega menu.

Built in Blade, Tailwind v4, plain JavaScript and Lucide. Newsroom is React; we copied the approach, not the code.

## Files

| Part | File |
| --- | --- |
| Tokens and shell styles | `resources/css/workspace.css` (imported by `app.css`) |
| Page-level variables mapped onto the tokens | `:root` block at the top of `resources/css/app.css` |
| Layout | `resources/views/layouts/app.blade.php` |
| Shell components | `resources/views/components/workspace/` (`rail`, `brand`, `panel-switch`, `nav`, `user-dock`, `topbar`, `drawer`, `page-header`, `command-palette`) |
| Shared components | `resources/views/components/` (`card`, `button`, `field`, `badge`, `alert`) |
| Navigation builder | `app/Support/Navigation/` (`WorkspaceNavigation`, `WorkspaceMenu`, `NavPanel`, `NavGroup`, `NavItem`, `QuickAction`) |
| Scripts | `resources/js/workspace/` (`dialog-focus.js`, `drawer.js`, `command-palette.js`) |
| Strings | `lang/en/workspace.php`, `lang/en_GB/workspace.php` |

## Tokens

MyAPES values fill the Newsroom Soft glass roles. Where MyAPES has no equivalent, the Newsroom value is used unchanged.

| Token | MyAPES | Newsroom | Notes |
| --- | --- | --- | --- |
| `--color-sg-ink` | `#12262C` | `#0B2B2C` | Body text |
| `--color-sg-muted` | `#40676A` | `#40676A` | Secondary text; 5.4:1 on base |
| `--color-sg-teal` | `#107481` | `#006E70` | Links, active item, primary button; 4.7:1 on base, 5.5:1 on white |
| `--color-sg-teal-dark` | `#0C5D68` | `#00585A` | Primary hover |
| `--color-sg-accent` | `#67C3CE` | `#00C4CC` | Decoration only; fails contrast as text |
| `--color-sg-base` | `#D2F4FA` | `#E8F6F6` | Page background under the gradient |
| `--color-sg-ok` / `-mist` | `#11613B` / `#DCF5E7` | same | Success badges and alerts |
| `--color-sg-warn` / `-mist` | `#8A4B0B` / `#FDEFD3` | same | Warnings |
| `--color-sg-error` / `-mist` | `#A12828` / `#FDE4E4` | same | Errors |
| `--color-sg-info` / `-mist` | `#005B5D` / `#D5F4F5` | same | Info, selected palette option |
| `--color-sg-channel-*` | CIC `#0C5D68`, Shelter `#8A4B12`, Clinic `#2F617D`, Pet `#2D6A4F` | Newsroom channel text colours | Behind white text; the same in both themes |
| `--color-focus` | `#2563EB` | `#2563EB` | 2px focus ring, 3px offset |
| `--radius-sg-card` / `-rail` / `-field` | `1.25rem` / `1.375rem` / `.75rem` | same | |
| `--shadow-sg-*` | tinted with `#107481` | tinted with `#006E70` | card, rail, primary, active, dialog |
| `--container-panel` | `70rem` | `70rem` | Content width |

Surface tokens (`--sg-surface`, `--sg-surface-strong`, `--sg-rail-surface`, `--sg-edge`, blur sizes) live on `.workspace-shell` (the `<body>`) so the dark theme and the fallbacks can swap them in one place. The background is a fixed `::before` layer with three radial washes: cyan `#7FE7EA`, lavender `#B9C6FF` and mint `#A7F3D0`.

The older page variables (`--ink`, `--surface`, `--brand-teal`, `--clay` and so on) are kept as aliases of these tokens, so existing page CSS picks up the palette without per-page changes.

## Layout

- **Desktop (64rem and up):** the rail is fixed 1rem from the top, bottom and left, 14.75rem wide, and scrolls internally. The content column is offset by `calc(14.75rem + 1rem)`.
- **Rail, top to bottom:**
  - Brand: a 40px mark (`public/logos/myapes-mark-{40,80,120}…png`), "MyAPES Account" and the current panel.
  - The Staff | Admin switch.
  - Flat groups under 11px uppercase headings.
  - The user dock, pinned to the bottom.
- **There are no submenus, collapsing groups or badges.**
- **Content header:** the breadcrumb, then the search pill and "View site". An H1 and subtitle appear only when a page sets `@section('page_title')` / `@section('page_subtitle')`. Pages that render their own H1 keep it.
- **Below 64rem:** a glass top card with Menu, the brand and Search. Menu opens a left drawer, `min(20rem, 88vw)` wide. The drawer is a copy of the desktop rail, made when it opens, so the rail markup exists once.
- **Auth pages:** `public.login`, `public.register`, `staff.login`, password and two-factor pages add `is-auth-page` to the body. The page card is centred under the horizontal logo.

## Panels and the builder

`WorkspaceNavigation::forRequest($user, $request)` returns a `WorkspaceMenu`. The layout composer (`CoreAdminServiceProvider`) shares it as `$workspaceMenu`. Blade never checks permissions for navigation.

| Panel | Who | Groups |
| --- | --- | --- |
| Public | Guests | Explore (public plugin links), Account (Log in, Register, Staff login), Support |
| Staff (labelled Account for public accounts) | Every signed-in user | Overview (Dashboard, Profile), one group per enabled module in Core > Modules order, Support (Help, Change log, public plugin links) |
| Admin | Users who can open at least one Admin page | Overview, People (Public users, Staff, Access), Modules (Modules, Plugins), System (Emails, Maintenance) |

Rules:

- **Gating:** items are filtered by ability, keeping the plugin `homeAbility` and the `.view-all` to `.view-own` fallback. Empty groups are dropped, and a panel with no groups is not offered. Hiding a link is not the security boundary: routes keep their middleware.
- **Panel switch:** it appears only when the user has both Staff and Admin. Each tab links to that panel's first item.
- **Active item:** resolved from the route name. The most specific matching pattern wins, so `shelter.tickets.*` beats `shelter.*`.
  - `account_type` (or the user being viewed) separates Public users from Staff.
  - Pages never pass an active key.

### Adding a plugin to the rail

1. Declare a `PluginNavigationItem` in the plugin manifest, giving the label, the `{module}.{plugin}.index` route, a Lucide icon, the order, the optional `requiredAbility` and the `moduleSlug`. The item appears under each enabled module, with its routes (`{module}.{plugin}.*`) marked as current.
2. To give staff a destination that depends on the user (as Recruitment does), contribute a `StaffPluginNavigationItem` to `StaffPluginNavigationRegistry`. It joins the module group its route patterns live under, replacing the manifest item if they overlap.
3. Add search synonyms through the manifest `searchKeywordsKey`. The palette matches on them.
4. If the plugin has a create form at `index#create`, the palette offers a "Create …" quick action to users who hold `{module}.{plugin}.create`. Put the label in `workspace.quick.create.{plugin}`.
5. Register any new Lucide icon in both lists in `resources/js/app.js`.

## Command palette

Ctrl K (⌘K on Apple devices), the header search pill or the top card's search button opens it. It is a dialog with a combobox (`aria-activedescendant`) over a listbox:
- Arrow Up and Down wrap, Home and End jump, Enter opens the selected option, and Escape closes.
- Every search term must appear in the option's label, section or keywords.

The options are rendered as JSON (`data-workspace-palette-index`) from `WorkspaceMenu::paletteCommands()`, in this order:
1. Quick actions: create forms, the panel switch, View site and Your account.
2. Every permitted nav item.
3. Plugin and plugin settings entries from the Admin search catalogue.

## Shared components

| Component | Classes | Use |
| --- | --- | --- |
| `<x-card>` | `.panel` (glass card) | Page sections; `flat` for nested tiles |
| `<x-button variant="primary\|secondary\|ghost\|danger" size="sm" href="…" icon="…">` | `.sg-btn`, `.sg-btn-*` | Buttons and button-styled links |
| `<x-field label name type hint>` | `.sg-field` | Labelled input or textarea with hint and error, wired with `aria-describedby` |
| `<x-badge tone="ok\|warning\|error\|info\|neutral">` | `.sg-badge-*` | Status chips |
| `<x-alert tone title>` | `.sg-alert-*` | Flash messages and inline notices |

Other classes: `.sg-card`, `.sg-icon-btn`, `.sg-tabs` / `.sg-tab` (or `.admin-nav` for in-page section tabs), `.sg-empty` and `.sg-visually-hidden`.

## Accessibility

- **Structure:**
  - A skip link comes first and targets `<main id="main-content" tabindex="-1">`.
  - The rail is an `<aside>` with `<nav aria-label="Workspace navigation">`, and its headings are tied to their lists with `aria-labelledby`.
- **Current state:** exactly one nav link has `aria-current="page"`, and so does the last breadcrumb.
- **Icons:** all have `aria-hidden="true"`, and icon-only buttons have names.
- **Drawer and palette:** focus moves in, Tab is trapped, Escape closes, the page behind is `inert` with `aria-hidden` and scrolling is locked, and focus returns on close.
- **Media fallbacks:**
  - `prefers-reduced-transparency` swaps in near-opaque surfaces and removes `backdrop-filter`.
  - `prefers-reduced-motion` turns off the drawer slide and the fades.
  - Browsers without `backdrop-filter` get opaque surfaces.
- **Hit targets:** at least 2.25rem for the switch, 2.5rem for links and 2.75rem for buttons.

## Dark theme and print

The theme toggle in the dock sets `data-theme="dark"`, and the choice persists in `localStorage` (`myapes-theme`). The dark theme sets every token to its dark value and retints the gradient from `#12262C` and `#107481`. Print hides the rail, top card, drawer, palette, header tools and mascot, and prints the content full width with no filters or gradients.

## Visual QA

Screenshots for 0.44.0 are in `docs/design-qa/workspace-shell-*.png`. They cover 1440×900, 1024×768, 768×1024 and 375×812, in light, dark and reduced-transparency modes.
