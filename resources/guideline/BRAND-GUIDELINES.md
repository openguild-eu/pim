OpenGuild is a European home for open-source business software, starting with a warehouse management system (WMS) and a product information management system (PIM). Every product is free to download and run (the Community Edition), and OpenGuild also installs, extends and updates it for clients who want that. The software is built with the Laravel framework and Filament. Build every page so it feels like a calm, confident, plain-spoken European software company: navy and white, one warm amber signal, big bold headlines, no decoration for its own sake.

## Content fundamentals

- **Say who owns what.** The idea behind the brand is ownership: the client owns the code and the data, and can shape the software to the way they work. Lead with that, not with price. Cost is a smaller, third point.
- **Name the problem, then the answer.** Write section headings as the situation the visitor is in ("Your needs don't fit one-size-fits-all SaaS"), and the body as what OpenGuild does about it.
- **Plain, direct English.** Address the visitor as "you", the company as "we". Short sentences, no jargon, no buzzwords, no exclamation marks, no emoji. Contractions are fine.
- **Casing.** Sentence case for headings, buttons and links ("Download the Community Edition", "Talk to us"). Only the `eyebrow` style is uppercase, and only via CSS.
- **Always two options.** Present "Option 1 · Do it yourself" (Community Edition, free) next to "Option 2 · We do it for you" (installation, custom modules, updates, support). Never hide the free path.
- **Real examples from the site:** "Software you can own. Help when you want it." · "Open source, with a team behind it" · "Start free, pay only for help" · "Not sure which option fits?" · "We will say honestly whether you need us at all."
- **Placeholders.** Prices and the contact address are written `[YOUR PRICE]` and `[YOUR EMAIL]` until the owner supplies them. Never invent figures, customers, testimonials or statistics.
- **Name.** Write `OpenGuild` (one word, capital G). In the wordmark "Open" is medium weight and "Guild" is extra bold.
- **Technology claim.** State once, plainly: all software is built on the proven Laravel framework with Filament for the admin interface. Show Laravel and Filament as plain text labels, never as their logos.

## Visual foundations

- **Colour.** Page ground is `surface`; alternate bands use `surface-soft`. Text is `ink` (headings) and `ink-muted` (body). Use `blue` for eyebrow labels and links. Use `amber` as a fill for the primary call-to-action and the keystone, with `ink` text on it. Never set text in `amber` on a light ground. Use at most one dark band (`surface-dark`) per two or three light bands, for services and the closing banner.
- **Type.** One family: Manrope (`sans`), weights 500 (body), 600 (navigation) and 800 (headings, buttons, labels). Headlines use `h1` and `h2` with tight negative letter-spacing; body text uses `body` (17px) or `body-lg` (18px). Load Manrope from Google Fonts with weights 500, 600 and 800 and `display=swap`, or self-host it.
- **Layout.** Centre content in a `container` (1120px) with `space-4` side padding. Every section has `space-8` padding top and bottom. Space a section header and its content `space-6` apart. Build rows with flex-wrap so columns stack on a phone (cards use `flex: 1 1 460px`, small cards `flex: 1 1 240px`), and make the hero two columns that wrap.
- **Surfaces.** Cards are filled blocks, not outlined or shadowed: `surface-soft` cards on `surface`, `surface-dark-raised` cards on `surface-dark`, white bordered cards (`line`, 1px) on `surface-soft` for pricing. Highlight one pricing card with a 2px `ink` border. Do not use drop shadows or gradients.
- **Radii.** Buttons `radius-sm`; problem and service cards `radius-md`; product, hero and pricing cards `radius-lg`; the closing contact banner `radius-xl`.
- **Buttons.** Primary action on a light ground: `amber` fill, `ink` text. Primary on `surface` in navigation or inside a card: `ink` fill, `on-dark` text. Secondary: 2px `ink` border, `ink` text, transparent fill. Height 52px for hero and banner buttons, 48px in cards, 44px in navigation. Label in the `button` style.
- **Links.** Navigation links are `ink`, 600 weight, no underline, with a 44px minimum target. Inline links are `blue`.
- **Imagery.** None is required. Use the keystone mark (for PIM and general use) and the open-cube product mark (for WMS, `openguild-wms-cube.svg`) as the only illustration, at 48px above a product name. Do not use stock photos, 3D renders, emoji or gradient washes.
- **Motion.** Keep it minimal: a colour change on hover, nothing that moves on scroll.
- **Focus.** Give every interactive element a visible 3px `blue` outline with a 2px offset (7:1 on `surface` and `surface-soft`). On `surface-dark`, use `amber`.
- **Accessibility.** Keep text at 4.5:1 or better using only the token pairs in each colour's usage note. Make every clickable element at least `tap` (44px). Add `aria-label` to icon-only links.

## Logo

Use the files in `assets/Logos`. Never redraw, recolour, stretch or re-set the wordmark in a font.

- **Mark.** The keystone: two pillars carrying an amber keystone. It reads as shared stones held together by the top one.
- **Lockup.** Mark left, wordmark right, 26 units apart on a 100-unit mark (about a quarter of the mark's height). Use `openguild-logo-color.svg` on `surface` and `surface-soft`; use `openguild-logo-white.svg` (white pillars and text, amber keystone) on `surface-dark` or any dark photo.
- **One colour.** Where a single ink is required (stamps, embossing, fax), use `openguild-logo-mono-navy.svg` or `openguild-logo-mono-white.svg`.
- **Clear space.** Keep at least the width of one pillar (about 30% of the mark's height) empty around the logo.
- **Minimum size.** The lockup is never narrower than 120px on screen or 30mm in print. Below that use `openguild-icon-color.svg` alone, with a 16px minimum.
- **App icon and favicon.** Use `openguild-app-icon.svg` (white pillars and amber keystone on a navy rounded square), exported at 32, 180, 512 and 1024px as PNG.
- **Never** put the colour lockup on a dark ground, put the amber keystone on amber or yellow, add shadows or outlines, rotate it, or change the weights of "Open" and "Guild".

## Page structure

Build the marketing site as one long page with these sections in this order. Each has an id the navigation links to.

1. **Navigation** (white, bottom border `line`): lockup left; links Why, Products, Services, Pricing; GitHub icon link; "Talk to us" button in `ink`.
2. **Hero** (`surface-soft`): `eyebrow` "Open source from Europe", `h1`, `lead`, two buttons (amber "Download the Community Edition", secondary "See how we help"); on the right two stacked cards: Option 1 (white, `line` border) and Option 2 (`surface-dark`, amber label).
3. **Why OpenGuild** (`#why`, white): header, then four problem cards in `surface-soft`, each with a small uppercase "The problem" label, an `h4` title and a body sentence: ownership, needs that don't fit SaaS, costs that grow per user, software nobody can maintain (Laravel and Filament).
4. **Products** (`#products`): header, then two product cards (`surface-soft`, `radius-lg`, 32px padding) for OpenGuild WMS and OpenGuild PIM, each with its mark at 48px, an `h3` name, a one-sentence description and two buttons (Download in `ink`, Source code secondary).
5. **Technology**: one block with a 2px `ink` border and `radius-lg`, an `eyebrow`, an `h2` "Built on Laravel and Filament", a paragraph, and two text labels (Laravel on `ink`, Filament on `amber`).
6. **Services** (`#services`, `surface-dark`): header in `on-dark`, then four `surface-dark-raised` cards: Installation, Custom modules, Updates, Support.
7. **How it works** (white): `h2`, then three numbered steps with the number in `amber` at 44px, an `h4` title and body.
8. **Pricing** (`#pricing`, `surface-soft`): header, then three white cards: Community €0, Setup `[YOUR PRICE]` (2px `ink` border), Care plan `[YOUR PRICE]` per month.
9. **Contact** (`#contact`): a `surface-dark` banner with `radius-xl`, `h2` "Not sure which option fits?", one sentence and an amber "Contact us" button linked to `mailto:`.
10. **Footer**: small mark and wordmark left; "openguild.eu · Open source from Europe" and the GitHub icon link (https://github.com/openguild-eu) right.

## Iconography

Use inline SVG only, drawn on a 16 or 24px grid in `currentColor`; no icon fonts and no emoji. The one recurring icon is the GitHub mark (24–26px, `ink`, 44px target) in the navigation and the footer; link it to https://github.com/openguild-eu with `aria-label="OpenGuild on GitHub"`.

## Building the site

- **Software.** The OpenGuild products themselves are Laravel applications with a Filament admin. Keep that stack; do not introduce another front-end framework for the products.
- **Marketing site.** Build it as plain Blade or static HTML with one stylesheet. Declare the tokens in `tokens.json` as CSS custom properties on `:root` (`--ink`, `--amber`, `--space-8`, `--radius-lg` and so on) and use only those, never raw hex values or pixel sizes in components.
- **Language.** English first. Mark the document language and keep copy ready for a Dutch version.
- **Hosting.** European servers; say so in the copy only when it is true for that deployment.
