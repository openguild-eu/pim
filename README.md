# PIM

A Product Information Management (PIM) system — a central platform to collect, manage, enrich, and distribute product data.

## Overview

The application is an admin panel built with [Filament](https://filamentphp.com) v4, branded "OpenGuild" in the UI. It gives teams a single place to maintain product data, attributes, categories, distribution channels, and relationships between products, plus an audit trail of who changed what.

Notable features:

- Per-tenant OIDC SSO login for the admin panel
- A "Needs Attention" dashboard page surfacing data-quality issues
- Product completeness scoring
- Products supporting multiple attribute sets
- Category filtering on the product table

## Tech Stack

- **PHP** ^8.3, **Laravel** ^13.17
- **Filament** ^4.0 — admin panel, with `filament/spatie-laravel-media-library-plugin` ^4.0
- **internachi/modular** ^3.0 — modular architecture, feature modules live in `app-modules/` and are wired in as local path repositories
- **spatie/laravel-activitylog** ^5.0 + **alizharb/filament-activity-log** ^1.3 — audit trail / activity log UI in the panel
- **spatie/laravel-medialibrary** ^11.0 — file/image management
- **spatie/laravel-permission** ^8.4 — roles and permissions
- **socialiteproviders/openidconnect** ^1.0 — generic OIDC SSO, configured per-tenant
- **Frontend:** Tailwind CSS ^4, Vite ^8, `laravel-vite-plugin`
- **Testing:** Pest ^5 (+ `pest-plugin-laravel`)
- **Static analysis:** Larastan

## Modules

Feature modules live in `app-modules/`:

- `attributes` — custom product attributes and attribute sets (EAV-style schema)
- `categories` — product categorization / taxonomy
- `channels` — sales/distribution channels and API key management
- `products` — core product catalog entity
- `relations` — relationships between products (e.g. cross-sell, variants, bundles)
- `users` — user accounts and role management

## Getting Started

### One-shot setup

```sh
composer run setup
```

This installs PHP dependencies, copies `.env.example` to `.env`, generates an app key, runs migrations, and installs & builds npm assets.

### Manual setup

If you'd rather run the steps yourself:

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

### Environment

`.env.example` defaults to:

- SQLite for the database
- Database-backed session, cache, and queue drivers
- Log-based mail driver

MySQL and Redis are supported via commented-out alternatives. An AWS/S3 config block is present for file storage. Note `APP_NAME=PIM` and a `CENTRAL_DOMAIN` setting, which supports per-tenant domain routing (used by the per-tenant SSO feature).

### Serving the app

Locally the app is served by [Laravel Herd](https://herd.laravel.com) at `https://pim.test` — you don't need to run a dev server to browse it. `composer run dev` / `npm run dev` below are for asset watching, not serving.

## Development

```sh
composer run dev    # Starts the Laravel dev environment (server, queue worker, Vite, etc.) via `php artisan dev`
npm run dev          # Vite dev server alone
npm run build        # Production asset build
```

### Testing

```sh
composer run test    # Clears config cache, then runs `php artisan test`
vendor/bin/pest       # Run the test runner directly
```

### Code style

```sh
vendor/bin/pint --dirty --format agent
```

## License

MIT licensed.
