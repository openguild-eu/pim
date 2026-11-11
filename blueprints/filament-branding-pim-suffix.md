# Filament Blueprint: "| pim" Suffix After the Admin Panel Logo

## Goal

The OpenGuild brand lockup SVGs used as the Filament admin panel logo
(`public/images/brand/openguild-logo-color.svg` for light mode,
`public/images/brand/openguild-logo-white.svg` for dark mode — set up by the
already-completed `blueprints/filament-branding.md` plan) render the
"OpenGuild" wordmark baked into the SVG paths. This is a PIM (Product
Information Management) system for the OpenGuild brand, not OpenGuild
itself, so add the literal text `| pim` immediately after the logo mark in
both light and dark mode, in the sidebar/topbar brand area.

Scope is this one visual element only. Do **not** touch `->brandName(...)`,
`->favicon(...)`, `->brandLogoHeight(...)`, colors, font, tenancy, auth, SPA,
`ActivityLogPlugin`, navigation groups, middleware, or discovery calls in
`app/Providers/Filament/AdminPanelProvider.php` — only the two lines
specified in step 2 change.

## Why a Blade view, not a plain asset() string (verified)

`Panel::brandLogo()` / `Panel::darkModeBrandLogo()` accept
`string|Htmlable|Closure|null` (verified in
`vendor/filament/filament/src/Panel/Concerns/HasBrandLogo.php`). A plain
string (what's currently configured) is rendered as a bare `<img src="...">`
with no way to place sibling text next to it. A `Closure` returning a Blade
`View` (which implements `Htmlable`) is rendered differently — verified in
`vendor/filament/filament/resources/views/components/logo.blade.php`:

```blade
@if ($logo instanceof Htmlable)
    <div {{ $attributes->class([$getLogoClasses($isDarkMode)])->style([$logoStyles]) }}>
        {{ $logo }}
    </div>
@elseif (filled($logo))
    <img ... src="{{ $logo }}" ... />
@endif
```

So when `brandLogo()`/`darkModeBrandLogo()` return a view, Filament wraps
*our own markup* in a `<div>` carrying the `fi-logo fi-logo-light`/
`fi-logo-dark` classes and the `height: 2rem` (`brandLogoHeight`) inline
style — the light and dark variants are both rendered into the DOM
simultaneously, and Filament's own CSS shows/hides the correct one. We do
**not** need to add any `dark:` Tailwind variants or JS ourselves — just
render the logo image plus the `| pim` text inside the content we return,
sized to fill that wrapping div's height.

Doc reference: https://github.com/filamentphp/filament/blob/4.x/docs/08-styling/01-overview.md
("Adding a logo" — "you may directly pass HTML to the `brandLogo()` method").

## 1. Create `resources/views/filament/admin/brand-logo.blade.php`

This directory does not exist yet in this app — create it. One shared
partial is used for both light and dark mode; the logo URL and the suffix
text color are passed in as view data so there is a single source of markup.

Use plain inline styles, not Tailwind utility classes, because this file
lives under `resources/views/filament/**/*`, which is not currently listed
in any `@source` directive in `resources/css/filament/admin/theme.css`
(confirmed by reading that file — it only sources `app/Filament/**/*` and
the four `app-modules/*/src/Filament/**/*` globs). Tailwind v4 only
generates CSS for classes it can see via `@source`; utility classes used
here would silently be purged from the build. Do not add a new `@source`
line to `theme.css` for this — that widens the scope of this plan. Inline
styles avoid the problem entirely.

File contents (exact — this is a plain view rendered via
`view('filament.admin.brand-logo', [...])` with an array, not a Blade
component tag, so no `@props` block is needed):

```blade
<span style="display: inline-flex; align-items: center; height: 100%; gap: 0.5rem;">
    <img
        src="{{ $logo }}"
        alt="OpenGuild"
        style="height: 100%; width: auto; display: block;"
    />
    <span style="font-size: 0.8125rem; font-weight: 600; line-height: 1; color: {{ $textColor }}; white-space: nowrap;">
        | pim
    </span>
</span>
```

`height: 100%` on the outer `<span>` and the `<img>` makes both fill the
parent `<div>` that Filament puts the `height: 2rem` style on (see "Why a
Blade view" above), so the logo keeps rendering at the same
`brandLogoHeight` as before.

## 2. Edit `app/Providers/Filament/AdminPanelProvider.php`

Replace only these two lines:

```php
->brandLogo(asset('images/brand/openguild-logo-color.svg'))
->darkModeBrandLogo(asset('images/brand/openguild-logo-white.svg'))
```

with:

```php
->brandLogo(fn () => view('filament.admin.brand-logo', [
    'logo' => asset('images/brand/openguild-logo-color.svg'),
    'textColor' => 'rgba(15, 27, 61, 0.6)',
]))
->darkModeBrandLogo(fn () => view('filament.admin.brand-logo', [
    'logo' => asset('images/brand/openguild-logo-white.svg'),
    'textColor' => 'rgba(255, 255, 255, 0.7)',
]))
```

`rgba(15, 27, 61, 0.6)` is the existing `ink` brand token (`#0F1B3D`, see
`resources/guideline/tokens.json` / `blueprints/filament-branding.md`) at
reduced opacity, for a muted suffix next to the full-strength light-mode
logo. `rgba(255, 255, 255, 0.7)` is white at reduced opacity to match, for
the dark-mode logo (which renders in solid white, `#FFFFFF`).

Leave `->brandLogoHeight('2rem')` and every other line in the `panel()`
chain unchanged.

## 3. Build and verify

1. No asset pipeline changes are needed for this step (no new CSS rules, no
   Vite config change) — only a new Blade view and a provider edit. If
   `npm run dev`/`composer run dev` is not already running, nothing needs
   rebuilding for Blade/PHP changes to take effect; just refresh the page.
2. Load the admin panel in the browser (Herd serves it automatically — use
   `get-absolute-url` to get the correct URL; don't start a server).
3. In light mode: confirm the sidebar/topbar shows the color OpenGuild logo
   immediately followed by `| pim` in a smaller, muted navy, vertically
   centered with the logo, at the same height as before (no layout shift /
   logo shrinking).
4. Toggle dark mode (Filament's theme switcher): confirm the logo swaps to
   the white lockup and `| pim` renders in a muted white/gray, still legible
   against the dark sidebar background.
5. Confirm no console errors and no "Unable to locate file in Vite
   manifest" error.
6. Run `vendor/bin/pint --dirty --format agent` (a PHP file was touched).
7. No new or changed automated tests are required — this is a pure
   styling/copy change with no behavior to regression-test (per this
   project's test-enforcement rule: pure styling and copy-only changes don't
   need tests).

## Report back

State: whether the new Blade view was created at the exact path above,
the exact two-line diff applied to `AdminPanelProvider.php`, the result of
every check in step 3 (pass/fail, with what was seen for any fail in both
light and dark mode), and the `pint` result.
