# Local setup

Verified end to end on macOS (Apple Silicon) on 2026-10-19. Every command below
was actually run against a clean checkout.

## Prerequisites

| Tool     | Version to install | Notes |
| -------- | ------------------ | ----- |
| PHP      | 8.4                | CI and Upsun both run 8.4. 8.5.11 also works locally; `composer.json` allows `^8.3`. |
| Composer | 2.x                | |
| Node     | 24 (see `.node-version`) | Sylius 2.3 requires `^22.13 || >=24` (enforced). CI uses 24.x; Node 26 also builds cleanly. |
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
| `/` | Storefront. Shop URLs have no locale prefix; old `/en_US/...` links redirect (301). |
| `/safety-ppe` | A category page: categories live at the site root, products at `/products/{slug}`. |
| `/quote` | Public request-for-quote form. |
| `/admin` | Sylius admin: catalog, orders, customers |
| `/backoffice` | EasyAdmin dashboard for the RFQ queue (`App\Controller\Admin\DashboardController`) |

A non-interactive install creates one administrator:

```
sylius@example.com / sylius
```

Both `/admin` and `/backoffice` sit behind the same `admin` firewall, so one
login covers both.

**Before any shared/staging/prod deploy: change this password** (or create a
real administrator and delete this one) — `sylius@example.com` / `sylius` is
a well-known default seeded by every `sylius:install`, not a secret specific
to this app. Left as-is locally since there's no real exposure on a
machine only you can reach; flagged here instead of changed automatically,
since it's a database value (not code) and needs doing again on every fresh
install anyway, same as the channel name (see main project doc).

## Checks

These are what CI runs (`.github/workflows/`):

```bash
php -d memory_limit=-1 bin/console lint:twig src
php -d memory_limit=-1 bin/console lint:yaml src
php -d memory_limit=-1 bin/console lint:container
php -d memory_limit=-1 vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite functional   # needs the test database below
```

### End-to-end tests (`tests/Functional`)

These boot the real app against a separate `usiscs_test` database with a
small fixture set (one channel, `admin` / `e2e-admin-password`), defined in
`config/packages/app_e2e_fixtures.yaml`. One-time setup, and again after a
new migration (loading the fixtures wipes that database, never your dev one):

```bash
APP_ENV=test php -d memory_limit=-1 bin/console doctrine:database:create --if-not-exists
APP_ENV=test php -d memory_limit=-1 bin/console doctrine:migrations:migrate -n
APP_ENV=test php -d memory_limit=-1 bin/console sylius:fixtures:load e2e -n
APP_ENV=test php -d memory_limit=-1 bin/console app:catalog:setup-categories
```

They also need built assets (`npm run build`). Plain `vendor/bin/phpunit`
runs both suites.

To browse the test database in a browser, `php -S` needs
`-d variables_order=EGPCS`, or it ignores `APP_ENV=test` and serves dev:

```bash
APP_ENV=test php -d variables_order=EGPCS -d memory_limit=-1 -S 127.0.0.1:8001 -t public bin/dev-router.php
```

## Importing products

Products come in from a spreadsheet (and later the supplier feed) through one
importer (`src/Catalog/Import/`). Send the client the template, then import
what they fill in:

```bash
php -d memory_limit=-1 bin/console app:catalog:import-template catalog-import-template.xlsx
php -d memory_limit=-1 bin/console app:catalog:import products.xlsx --dry-run   # check, saves nothing
php -d memory_limit=-1 bin/console app:catalog:import products.xlsx             # -v: a line per product
```

- Columns are matched by header name: `sku`, `name`, `category`, `price` are
  required; `description`, `short_description`, `manufacturer`, `part_number`,
  `unit_of_measure`, `stock_qty`, `image_url`, `go_live` are optional.
- **New products arrive hidden.** `go_live=yes` publishes a new product straight
  away if it's ready (name, category, price in every channel).
- **Re-importing updates products, but never a field an admin has changed**
  (per field: e.g. an admin's price edit sticks while stock keeps updating),
  and never changes whether a product is live.
- Products made by hand in the admin are never touched by an import.
- **SKUs** may contain `.`, `/` and `+` (ORS uses them); Sylius codes can't, so
  they become `_` in the product code (`012-92-500-7.5-8` → `012-92-500-7_5-8`).
  The original is kept for the ORS syncs, and `--sku` accepts either.
- **`unit_of_measure`** is what the price is for: `each`, `box`, `case of 12`,
  `case of 144 pairs`, or ORS's `144 PR = 1 CA`. Shown under prices
  ("per case of 144 pairs") and in the cart ("Sold per case of 144 pairs").
- Bad rows are listed with their spreadsheet row number and skipped; the rest
  still import. The command exits non-zero if any row was skipped.

- **`group` + `variant_name`** sell several SKUs as one product with a choice
  (sizes, colours): rows with the same `group` become variants of the product
  whose code is the group. Its name, category, description and manufacturer
  come from the group's first row; each SKU keeps its own price, stock, pack
  unit and `variant_name` ("Size 10, black"). A SKU that is already a product
  of its own isn't moved into a group (delete it first).

### Loading the ORS catalog

```bash
php -d memory_limit=-1 bin/console app:catalog:prepare-ors-import \
    ~/Downloads/USISCS_ORS_vs_Grainger_Price_Comparison.xlsx ors-import.xlsx \
    --families ~/Downloads/ORS_Nasco_2737_Direct_Ship_Review.xlsx
php -d memory_limit=-1 bin/console app:catalog:import ors-import.xlsx --dry-run
```

It reads the "ORS price feed" sheet (and product families from "SKU review"):
price = ORS list price per selling unit, `unit_of_measure` = ORS's conversion,
families of 2+ priced SKUs become groups with variant names drafted from ORS's
short descriptions (marked in the `review` column), all as hidden drafts.
SKUs without an ORS price are listed on the "Skipped" sheet. Categories are a
keyword stand-in (`src/Catalog/Ors/InterimCategory.php`) until the client
approves the category tree. From the 2026-10-03 feed: 2,549 SKUs → 1,390
products (525 with a choice of 2+), 188 skipped.

**Taking products live:** Sylius admin → Catalog → Products → filter
*Enabled: No* → tick products → **Go live** (or **Take offline**). Products that
aren't ready are listed with the reason and left hidden.

## ORS Nasco sync (stock and cost)

Two commands keep imported products in step with ORS Nasco, the supplier.
They only touch products created by an import, never change whether a
product is live, and write nothing if an ORS call fails.

```bash
# Stock: on hand = ORS's total across all warehouses (one REST call).
php -d memory_limit=-1 bin/console app:ors:sync-stock --dry-run -v
# Cost: what ORS charges us per selling unit (SOAP, 40 SKUs per call).
php -d memory_limit=-1 bin/console app:ors:sync-cost --dry-run --sku=037-1001
```

- Drop `--dry-run` to save. `--sku` (repeatable) limits the run; `-v` lists
  every change.
- Stock always follows ORS, even over an admin edit; SKUs ORS doesn't list
  keep their stock.
- Cost is stored in `app_supplier_item`. The selling price is never changed,
  but products priced at or below cost are reported, and "Go live" refuses
  them.

Credentials go in `.env.local` (or Upsun variables), never `.env`. The
defaults in `config/services.yaml` point at ORS's **test** endpoints.

| Variable | What |
|---|---|
| `ORS_OAUTH_TOKEN_URL`, `ORS_OAUTH_CLIENT_ID`, `ORS_OAUTH_CLIENT_SECRET` | Stock API (Workato, OAuth 2 client credentials) |
| `ORS_CUSTNO`, `ORS_PA_PASSWORD`, `ORS_PA_PASSWORD2` | Price service account # and its two passwords |
| `ORS_AVAILABILITY_URL`, `ORS_PA_URL` | Production endpoints (drop `test`/`TEST` from the defaults) |

On Upsun, the crons are in `.upsun/config.yaml`, commented out until the
credentials are set.

## Payments (Stripe)

Stripe is the only card processor (`flux-se/sylius-stripe-plugin`). It's set
up in the Sylius admin, not in env files: the keys are stored in the database,
encrypted with the key at `SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH` (locally the
committed `config/encryption/test.key`; on Upsun a key generated on first
deploy in the `config/encryption` mount).

1. In Stripe (test mode first): Developers → API keys. Copy the publishable
   key (`pk_test_...`) and create a **restricted** key (`rk_test_...`; the
   plugin refuses `sk_` keys) with write access to Checkout Sessions, Payment
   Intents and Refunds, and read access to Events and Invoices (what the
   plugin calls).
2. Sylius admin → Configuration → Payment methods → Create → **Stripe
   Checkout**. Code `stripe`, enable it in the channel, paste both keys.
3. In Stripe: Developers → Webhooks → Add endpoint
   `https://usiscs.com/payment-methods/stripe` (the payment method's code at
   the end) with the events `checkout.session.completed`,
   `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed`, `checkout.session.expired` and
   `charge.refunded`. Paste its signing secret (`whsec_...`) into the payment
   method's webhook secret keys.
4. Place a test order with card `4242 4242 4242 4242`. Repeat with live keys.

Locally, forward webhooks with the Stripe CLI:
`stripe listen --forward-to http://127.0.0.1:8000/payment-methods/stripe`.

## Catalog categories

The category tree (the six categories on /services) is created by an
idempotent command. Run it once per environment, e.g. after a fresh install
or on Upsun via `upsun ssh`. It is not in the deploy hook on purpose: a
category deleted in the admin would come back on every deploy.

```bash
php -d memory_limit=-1 bin/console app:catalog:setup-categories
```

PHPStan runs at **level 9** and is currently clean. It picks up
`phpstan.dist.neon` automatically; create a gitignored `phpstan.neon` only if
you want to override something locally.

Behat inherits Sylius's entire upstream suite from
`vendor/sylius/sylius/src/Sylius/Behat/Resources/config/suites.yml` —
`features/` in this repo is empty. Those suites take ~45 minutes in CI.

## Lead emails (RFQ and contact form)

Every /quote and /contact submission is saved first (/backoffice → RFQ
Requests / Contact Messages), then emailed to the team:

- **To:** the channel's contact email (Sylius admin → Channels → Contact
  email). If it's blank, nothing is emailed and a warning is logged.
- **From:** `MAILER_FROM` (default `no-reply@usiscs.com`; set in
  `config/services.yaml`). Sylius's own emails (order confirmation, password
  reset) use it too.
- **Reply-To:** the customer, so Reply answers them directly.
- **Sent via:** `MAILER_DSN`. Locally `null://null` (dropped). To see real
  emails locally, run Mailpit (`brew install mailpit && mailpit`), set
  `MAILER_DSN=smtp://127.0.0.1:1025` in `.env.local`, and open
  http://127.0.0.1:8025.

On Upsun, set the provider's DSN as a sensitive variable
(`upsun variable:create --level environment --name env:MAILER_DSN --sensitive true ...`).
Without it, `symfony var:export` falls back to Upsun's own SMTP relay, which
isn't set up for usiscs.com (no SPF/DKIM), so mail is likely to land in spam.
The provider must be verified for the `MAILER_FROM` domain.

## Database platform

This project is MySQL. `.upsun/config.yaml`, `.platform/services.yaml`,
`compose.yml` and the CI matrix all specify MySQL/MariaDB, and the project
migration is MySQL-only (it extends Sylius's `AbstractMigration`, which skips
itself on non-MySQL platforms).

Sylius core and the Stripe plugin ship their own migrations and register them
from `vendor/`, including separate PostgreSQL variants that are skipped at
runtime. So `migrations/` in this repo should only ever contain **deltas for
app-specific tables** (RFQ, contact message, import state, supplier item), never a full schema
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
