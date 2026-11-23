# Theme brand guide

MyAPES Account ships two visual **skins** (Glassy and Jungle), each in **light** or **dark** colour mode, plus optional **Calm mode**. The workspace shell, tokens and shared components stay the same; skins change surfaces, backgrounds and decoration. Implementation detail for the shell lives in [workspace-shell.md](workspace-shell.md); assets are listed in [art-register.md](art-register.md).

## Glassy (default)

**Glassy** is the default skin. It follows the Soft glass palette from the APES Newsroom admin: teal ink and links, frosted cards, radial gradient washes (cyan, lavender, mint) behind the page, and glass blur on the app bar and panels. Users see this as **Glassy** in the theme switcher.

- HTML: `data-skin="glassy"` on `<html>` (default when unset).
- CSS: `resources/css/themes/glassy.css` (named skin); tokens in `resources/css/workspace.css` and `resources/css/app.css`.
- Dark mode retints every `--color-sg-*` token and the background gradient; contrast targets WCAG 2.2 AA (see theme contrast tests).

Glassy is the reference look for new UI: if a component works in Glassy light and dark, it should work in Jungle with only skin-specific overrides.

## Jungle

**Jungle** keeps the same layout, typography and component behaviour as Glassy, with a warmer, leafy personality:

- Deeper greens on channels and accents (`resources/css/themes/jungle.css`).
- Decorative SVGs under `public/decor/jungle/` (canopy, leaf corners, vine divider) on cards, panels and section headings when Calm mode is off.
- Jungle-only rules in `resources/css/themes/jungle-decor.css`.

Jungle decorative assets load only when `data-skin="jungle"` and must stay within the theme asset budget on representative workspace pages (see `ThemeAssetBudgetTest` in CI).

## Light and dark colour mode

**Colour mode** is independent of skin:

| Control | HTML | Default |
| --- | --- | --- |
| Light | `data-theme="light"` | Yes |
| Dark | `data-theme="dark"` | No |

Both skins support light and dark. The switcher exposes **Light** and **Dark** as a second control group. Signed-in users persist skin, mode and Calm mode on their account; guests store choices in the browser (`localStorage` key `myapes-appearance`, with a legacy fallback from `myapes-theme` for mode only).

A small inline script in `resources/views/layouts/app.blade.php` applies appearance before paint to avoid a flash of the wrong theme (FOUC).

## Calm mode

**Calm mode** reduces visual motion and decoration without changing tasks or layout:

- Sets `data-calm="true"` on `<html>`.
- Hides **chrome critters** in the user menu and site footer (`.critter-chrome`).
- Turns off Jungle leaf, vine and canopy decorations; Jungle still uses its colour tokens.

Calm mode does **not** remove critters from error pages, empty states or inline mascot tips where they explain the page. Users toggle Calm mode from the same theme switcher as skin and mode.

`prefers-reduced-motion` and `prefers-reduced-transparency` media queries in the shell still apply on top of user choices (see [workspace-shell.md](workspace-shell.md#accessibility)).

## Critter cast

The **critter cast** is a fixed set of seven friendly animals used across Glassy and Jungle. The same SVG poses are used in both skins; only surrounding decoration differs.

| Name | Species | Poses |
| --- | --- | --- |
| Spike | bearded dragon | welcome, thinking, oops |
| Cleo | chameleon | welcome, thinking, oops |
| Dot | tree frog | welcome, thinking, oops |
| Speck | crested gecko | welcome, thinking, oops |
| Moss | tortoise | welcome, thinking, oops |
| Noodle | corn snake | welcome, thinking, oops |
| Rio | parrot | welcome, thinking, oops |

Assets live at `public/mascot/cast/{name}-{pose}.svg`. Blade uses `<x-critter>`; routing and tips use `App\Support\Theme\CritterCast` and `App\Support\MascotTips`. Menu and footer chrome default to Spike welcome (`user-menu.blade.php`, guest footer in `app.blade.php`).

Illustrations are original APES CIC artwork (see [art-register.md](art-register.md)). Do not swap different art per skin.

## Theme switcher

Component: `resources/views/components/theme-switcher.blade.php`  
Behaviour: `resources/js/workspace/theme-switcher.js` (local persistence; signed-in POST to `account.theme-preference.store`).

Three controls:

1. **Style** — Glassy or Jungle (`data-appearance-key="skin"`).
2. **Colour mode** — Light or Dark (`data-appearance-key="mode"`).
3. **Calm mode** — switch (`data-appearance-key="calm"`).

### Where users open it

| Audience | Location | Variant |
| --- | --- | --- |
| Signed in | **Avatar** → user menu, below account links | `variant="menu"` |
| Signed in | **Profile** → Appearance panel (`profile/_appearance.blade.php`) | `variant="profile"` |
| Guest (and anyone on pages with a footer) | **Site footer** → Theme | `variant="footer"` |

Guests only have client-side storage until they sign in; then the server becomes the source of truth (`data-appearance-source="server"` on `<html>`).

Strings: `lang/en/workspace.php` and `lang/en_GB/workspace.php` under `theme.*`.

## Copy and product voice

- User-facing names: **Glassy**, **Jungle**, **Light**, **Dark**, **Calm mode** (match the switcher labels).
- Describe Jungle as optional personality, not a separate product.
- Mention Calm mode when documenting accessibility or distraction-sensitive use (quiet rooms, sensory preference).

For product principles, see [PRODUCT.md](../../PRODUCT.md).

## Related files

| Topic | Path |
| --- | --- |
| Theme config | `config/themes.php` |
| Preference API | `App\Support\Theme\ThemePreference` |
| Skin CSS | `resources/css/themes/glassy.css`, `jungle.css`, `jungle-decor.css`, `menu-decor.css` |
| Tests | `tests/Feature/Theme/` |