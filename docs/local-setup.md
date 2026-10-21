# Local setup

Verified end to end on macOS (Apple Silicon) on 2026-10-19. Every command below
was actually run against a clean checkout.

## Prerequisites

| Tool     | Version to install | Notes |
| -------- | ------------------ | ----- |
| PHP      | 8.4                | CI and Upsun both run 8.4. 8.5.11 also works locally; `composer.json` allows `^8.3`. |
| Composer | 2.x                | |
| Node     | 20 (see `.node-version`) | CI uses 20.x/22.x. Node 26 also builds cleanly. |
| MySQL    | 8.4                | `compose.yml` and CI use MySQL 8.4; Upsun uses MariaDB 11.8. **Do not use PostgreSQL** — see "Database platform" below. |

Required PHP extensions: `intl`, `gd`, `pdo_mysql`, `mbstring`, `openssl`,
`curl`, `zip`, `sodium`, `exif`, `fileinfo`, `bcmath`.

### PHP memory limit

The Sylius container will not build under the default 128M CLI limit — you get a
misleading `Allowed memory size exhausted` in `SluggableListener` that masks the
real output. Either raise it in your `php.ini`:

```ini
memory_limit = 1G
```

or prefix every console call with `php -d memory_limit=-1`. The commands below
use the prefix so they work without editing `php.ini`.

## Setup

```bash
# 1. PHP dependencies
composer install

# 2. JS dependencies and asset build.
#    Must run after composer install: package.json resolves @sylius-ui/*
#    from file:vendor/... paths that do not exist until vendor/ is populated.
npm install
npm run build

# 3. Start MySQL (Homebrew example)
brew install mysql@8.4
brew services start mysql@8.4

# 4. Local, uncommitted configuration. .env.local is gitignored — put
#    credentials here, never in .env.
cat > .env.local <<'EOF'
APP_ENV=dev
APP_DEBUG=1
DATABASE_URL=mysql://root@127.0.0.1:3306/usiscs_%kernel.environment%?serverVersion=8.4.11&charset=utf8mb4
MAILER_DSN=null://null
EOF

# 5. Create the database, run migrations, seed currency/locale/channel/admin
php -d memory_limit=-1 bin/console sylius:install --no-interaction
```

`sylius:install` runs `doctrine:database:create` followed by
`doctrine:migrations:migrate`, so migrations are the source of truth for schema
— not `doctrine:schema:update`.

## Running it

There is no `symfony` CLI requirement; PHP's built-in server is enough:

```bash
PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=-1 -S 127.0.0.1:8000 -t public bin/dev-router.php
```

`bin/dev-router.php` lets the built-in server deliver real files (CSS, JS, images)
itself and sends everything else to Symfony. Don't pass `public/index.php` as the
router: Symfony then serves the CSS as `text/html` and the browser ignores it, so
the storefront renders unstyled.

| URL | What it is |
| --- | --- |
| `/` | Storefront (redirects to `/en_US/`) |
| `/quote` | Public request-for-quote form. Not locale-prefixed — `/en_US/quote` is a 404. |
| `/admin` | Sylius admin: catalog, orders, customers |
| `/backoffice` | EasyAdmin dashboard for the RFQ queue (`App\Controller\Admin\DashboardController`) |

A non-interactive install creates one administrator:

```
sylius@example.com / sylius
```

Both `/admin` and `/backoffice` sit behind the same `admin` firewall, so one
login covers both.

## Checks

These are what CI runs (`.github/workflows/`):

```bash
php -d memory_limit=-1 bin/console lint:twig src
php -d memory_limit=-1 bin/console lint:yaml src
php -d memory_limit=-1 bin/console lint:container
php -d memory_limit=-1 vendor/bin/phpstan analyse
php -d memory_limit=-1 vendor/bin/phpunit
```

PHPStan runs at **level 9** and is currently clean. It picks up
`phpstan.dist.neon` automatically; create a gitignored `phpstan.neon` only if
you want to override something locally.

Behat inherits Sylius's entire upstream suite from
`vendor/sylius/sylius/src/Sylius/Behat/Resources/config/suites.yml` —
`features/` in this repo is empty. Those suites take ~45 minutes in CI.

## Database platform

This project is MySQL. `.upsun/config.yaml`, `.platform/services.yaml`,
`compose.yml` and the CI matrix all specify MySQL/MariaDB, and the project
migration is MySQL-only (it extends Sylius's `AbstractMigration`, which skips
itself on non-MySQL platforms).

Sylius core and the payment plugins ship their own migrations and register them
from `vendor/`, including separate PostgreSQL variants that are skipped at
runtime. So `migrations/` in this repo should only ever contain **deltas for
app-specific tables** (currently the two RFQ tables), never a full schema
baseline.

## Gotchas

- **Re-running the installer.** `sylius:install --no-interaction` asserts that no
  `sylius@example.com` administrator exists and will fail on an already-seeded
  database. To start over:
  `bin/console doctrine:database:drop --force && bin/console sylius:install --no-interaction`.
- **`doctrine:schema:validate`** reports one pre-existing mapping warning about
  `Sylius\Component\Shipping\Model\ShipmentUnit#shipment`. It comes from stock
  Sylius (`OrderItemUnit` is the mapped resource instead) and is not caused by
  anything in this repo.
- **The storefront has no products** after install. Catalog data is a later
  milestone; `bin/console sylius:fixtures:load` loads Sylius demo data if you
  need something to click through.
