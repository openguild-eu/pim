# Filament Blueprint: OpenGuild Brand for Admin Panel

## Goal

Apply the OpenGuild brand (logo, colors, typeface, radius scale) defined in
`resources/guideline/BRAND-GUIDELINES.md` and `resources/guideline/tokens.json`
to the single Filament panel in this app (`admin`, provider at
`app/Providers/Filament/AdminPanelProvider.php`, Filament v4.16.0).

Scope is branding only: logo, favicon, panel colors, panel font, and the
border-radius scale. Do **not** touch tenancy, auth, multi-factor, SPA,
`ActivityLogPlugin`, navigation groups, middleware, or resource/page/widget
discovery in `AdminPanelProvider.php` — copy the file's existing structure and
only change the lines this plan specifies.

Do **not** touch `resources/css/app.css` or the existing `bunny('Instrument
Sans', …)` Vite font entry — that pipeline serves the non-Filament frontend
and must keep working unmodified.

## Design tokens (source: resources/guideline/tokens.json)

| token | hex | use in this plan |
|---|---|---|
| ink | `#0F1B3D` | navy — referenced by logo choice, not applied as a global recolor |
| blue | `#2547D0` | Filament `info` color |
| amber | `#F2B134` | Filament `primary` color |
| line | `#E3E7F2` | hairline border, used only in the optional CSS hook in step 4 |
| surface-dark | `#0F1B3D` | dark-mode ground — governs which logo variant is used in dark mode |

Radius scale: `sm` 12px, `md` 16px, `lg` 20px, `xl` 24px.

Typeface: **Manrope**, weights 500/600/800, loaded via Google Fonts
(`display=swap`) — distinct from the app's Instrument Sans.

Do not apply the full `ink`/`surface-dark`/`on-dark` palette to Filament's
`gray` color slot or to sidebar/topbar chrome. Filament's `gray` scale drives
borders, muted text, and contrast throughout every component; remapping it
wholesale is a full redesign, not a branding pass, and risks breaking
contrast in places the guideline doesn't specify. This plan only touches the
elements explicitly covered by Filament's panel-configuration API (logo,
favicon, `primary`/`info` colors, font) plus the radius scale via Tailwind
theme variables. If a full chrome recolor (sidebar/topbar backgrounds in
light mode, etc.) is wanted later, that's a separate follow-up to confirm
with the user — don't add it here.

## Verified APIs (Filament v4.16.0, via search-docs against filament/filament@4.x)

- Logo: `Panel::brandLogo(string|Closure $logo)`, `Panel::darkModeBrandLogo(...)`,
  `Panel::brandLogoHeight(string $height)`.
  https://github.com/filamentphp/filament/blob/4.x/docs/08-styling/01-overview.md
- Favicon: `Panel::favicon(string $url)`. Same doc, "Adding a favicon".
- Colors: `Panel::colors(array $colors)` accepts a hex string per slot (e.g.
  `'primary' => '#F2B134'`) and Filament auto-generates the full shade palette
  from it. Slots: `primary`, `danger`, `gray`, `info`, `success`, `warning`.
  https://github.com/filamentphp/filament/blob/4.x/docs/08-styling/01-overview.md
  and 03-colors.md
- Font: `Panel::font(string $fontFamily, ?string $provider = null)`. Default
  provider is Bunny Fonts; pass
  `Filament\FontProviders\GoogleFontProvider::class` to use Google Fonts as
  the guideline specifies.
  https://github.com/filamentphp/filament/blob/4.x/docs/08-styling/01-overview.md
  ("Changing the font provider")
- Custom theme: `php artisan make:filament-theme {panel}` scaffolds
  `resources/css/filament/{panel}/theme.css`, adds it to `vite.config.js`
  `input`, and registers `->viteTheme('resources/css/filament/{panel}/theme.css')`
  on the panel provider automatically. Manual fallback (if the command can't
  autoconfigure) is documented below in step 2.
  https://github.com/filamentphp/filament/blob/4.x/docs/08-styling/01-overview.md
  ("Creating a custom theme" / "Manual configuration")
- Theme CSS files use Tailwind v4 syntax: `@import` the base Filament theme,
  then `@source` entries for every directory containing Blade/PHP files that
  use Tailwind classes Filament needs to scan.
  https://github.com/filamentphp/filament/blob/4.x/docs/14-upgrade-guide.md
  ("Custom themes need to be upgraded to Tailwind CSS v4")

**Not verified against Filament docs** (standard Tailwind v4 behavior, used
here as-is): overriding `--radius-sm` / `--radius-md` / `--radius-lg` /
`--radius-xl` inside an `@theme` block in the theme CSS file retargets every
Tailwind `rounded-*` utility class Filament's own stylesheet uses via
`@apply`. This is documented Tailwind v4 CSS-first theming
(https://tailwindcss.com/docs/adding-custom-styles), not a Filament-specific
API. If it doesn't visibly change component radii when verified in the
browser, fall back to per-component CSS hook overrides instead (see step 4)
and report that discrepancy.

## 1. Copy brand assets into `public/`

Create `public/images/brand/` and copy these files from
`resources/guideline/logos/` (do not move/delete the originals in
`resources/guideline/`):

| Source | Destination |
|---|---|
| `resources/guideline/logos/openguild-logo-color.svg` | `public/images/brand/openguild-logo-color.svg` |
| `resources/guideline/logos/openguild-logo-white.svg` | `public/images/brand/openguild-logo-white.svg` |
| `resources/guideline/logos/openguild-app-icon-32.png` | `public/images/brand/openguild-app-icon-32.png` |

Only these three files are needed for this plan (light-ground lockup,
dark-ground lockup, and a favicon-sized app icon). The icon-only marks,
mono variants, other PNG sizes, and `openguild-wms-cube.svg` are not used by
this plan — leave them only in `resources/guideline/logos/`, don't copy them.

## 2. Scaffold the panel's custom theme

Run:

```bash
php artisan make:filament-theme admin
```

This installs Tailwind CSS v4 build dependencies if missing (the project
already has `tailwindcss` and `@tailwindcss/vite` in `package.json`, so this
should be a no-op or a minor addition — don't downgrade or replace those),
creates `resources/css/filament/admin/theme.css`, adds that file to the
`input` array in `vite.config.js`, and adds `->viteTheme('resources/css/filament/admin/theme.css')`
to `AdminPanelProvider.php`.

**Verify after running:**
- `vite.config.js`'s `input` array still contains `resources/css/app.css` and
  `resources/js/app.js` unchanged, plus the new
  `resources/css/filament/admin/theme.css` entry.
- `AdminPanelProvider.php` gained exactly one new call,
  `->viteTheme('resources/css/filament/admin/theme.css')`, with no other line
  changed.

**If the command reports it could not autoconfigure either file**, apply the
manual steps yourself:

In `vite.config.js`, add to the `laravel()` plugin's `input` array (keep the
two existing entries):
```js
input: [
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/css/filament/admin/theme.css',
],
```

In `AdminPanelProvider.php`, add `->viteTheme('resources/css/filament/admin/theme.css')`
to the panel chain (placement doesn't matter functionally; put it next to the
other styling calls added in step 3 for readability).

## 3. Edit `resources/css/filament/admin/theme.css`

The scaffolded file starts with:
```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';
```

This app is modular (`internachi/modular`): Filament resources for the
`categories`, `attributes`, `products`, and `channels` modules live under
`app-modules/{module}/src/Filament/**/*`, not under `app/Filament`. Replace
the scaffolded `@source` lines with:

```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../app/Filament/**/*';
@source '../../../../app-modules/categories/src/Filament/**/*';
@source '../../../../app-modules/attributes/src/Filament/**/*';
@source '../../../../app-modules/products/src/Filament/**/*';
@source '../../../../app-modules/channels/src/Filament/**/*';

@theme {
    --radius-sm: 12px;
    --radius-md: 16px;
    --radius-lg: 20px;
    --radius-xl: 24px;
}
```

(There is no `resources/views/filament/` directory in this app — omit that
`@source` line; it would point at nothing.)

Do not add any other rules to this file in this pass (no color overrides
here — colors are set via `->colors()` in step 4, not CSS).

## 4. Edit `app/Providers/Filament/AdminPanelProvider.php`

Add these imports alongside the existing `use` statements (keep all existing
imports, insert these among them):

```php
use Filament\FontProviders\GoogleFontProvider;
```

In the `panel()` method chain, make these changes and no others:

- Change `->brandName('PIM')` to `->brandName('OpenGuild PIM')`. This string
  is still used for the page `<title>` and as the accessible name even
  though the visual mark becomes the logo image below — it must stay, not be
  removed.
- Immediately after `->brandName(...)`, add:
  ```php
  ->brandLogo(asset('images/brand/openguild-logo-color.svg'))
  ->darkModeBrandLogo(asset('images/brand/openguild-logo-white.svg'))
  ->brandLogoHeight('2rem')
  ->favicon(asset('images/brand/openguild-app-icon-32.png'))
  ```
- Change the existing:
  ```php
  ->colors([
      'primary' => Color::Amber,
  ])
  ```
  to:
  ```php
  ->colors([
      'primary' => '#F2B134',
      'info' => '#2547D0',
  ])
  ```
  Remove the now-unused `use Filament\Support\Colors\Color;` import only if
  nothing else in the file still references `Color::` — check the full file
  first; if `Color` is unused elsewhere, delete that import line, otherwise
  leave it.
- Add a font call. Place it next to the `->colors([...])` call:
  ```php
  ->font('Manrope', provider: GoogleFontProvider::class)
  ```
- Leave `->viteTheme('resources/css/filament/admin/theme.css')` wherever
  step 2 placed it (either auto-added by the command, or added manually by
  you next to the calls above).

Do not change `->tenant(...)`, `->multiFactorAuthentication(...)`, `->spa()`,
`->plugins([...])`, any `->discoverResources(...)`/`->discoverPages(...)`/
`->discoverWidgets(...)` call, `->navigationGroups([...])`, or
`->middleware([...])`.

## 5. Build and verify

1. `npm run build` (or confirm `npm run dev` / `composer run dev` is already
   running) — must complete with no "Unable to locate file in Vite manifest"
   error for either `resources/css/app.css` or
   `resources/css/filament/admin/theme.css`.
2. Load the admin panel in the browser (Herd serves it automatically — use
   `get-absolute-url` to get the correct URL, don't start a server).
3. In light mode: confirm the sidebar/topbar shows the color lockup logo
   (`openguild-logo-color.svg`), the favicon in the browser tab is the
   OpenGuild app icon, primary buttons/accents render in amber (`#F2B134`)
   with readable text, and `info`-colored elements (e.g. badges using
   `->color('info')`, if any exist) render in blue (`#2547D0`).
4. Toggle dark mode (Filament's theme switcher): confirm the logo swaps to
   `openguild-logo-white.svg` and remains legible.
5. Inspect a card/button/modal with the browser's devtools and confirm its
   computed `border-radius` matches the new scale (12/16/20/24px depending on
   element) — if `--radius-*` theme overrides had no effect, fall back to
   `.fi-btn { border-radius: ... }` / equivalent CSS hook classes in
   `theme.css` instead, and report that the Tailwind `@theme` override
   didn't apply.
6. Visit a non-Filament page (e.g. the marketing `welcome.blade.php` route,
   if reachable) and confirm it still renders in Instrument Sans, confirming
   `resources/css/app.css` was unaffected.
7. If any PHP file was touched beyond `AdminPanelProvider.php`, run
   `vendor/bin/pint --dirty --format agent`. No new or changed tests are
   required — this is a styling-only change with no behavior to regression-test
   (per this project's test-enforcement rule: pure styling changes don't need
   tests).

## Report back

State: which steps completed, whether `make:filament-theme` auto-configured
`vite.config.js`/`viteTheme()` or needed the manual fallback, whether the
`@theme` radius override visibly worked or needed the CSS-hook fallback,
whether the `Color` import was removed, and the result of every check in
step 5 (pass/fail, with what was seen for any fail).
