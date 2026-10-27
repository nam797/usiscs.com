# usiscs.com progress

## Done
- Sylius 2.2 storefront set up; local setup verified on macOS (2026-10-19)
- Custom shop pages: About, Services, Contact, RFQ, Privacy, Terms, Sitemap
- RFQ system with admin review screen; form rate limiting; security headers
- Dependabot updates merged (latest 2026-10-22)
- CI fixed (2026-10-23): main had been red since the sylius/paypal-plugin 2.1.0
  bump dropped friendsofsymfony/rest-bundle while config/bundles.php still
  registered it. Also fixed 5 phpstan errors that slipped in while CI was red.
- Branding (2026-10-23): favicon set cut from the logo's hexagon mark (shop +
  admin), and the client logo replaces Sylius's in the admin sidebar and login.
  All logo uses point at assets/shop/images/logo.png, so a new logo is a one-file
  swap (favicons need regenerating separately; they're static files in public/).

- Admin dark mode fixed (2026-10-23): the sylius/paypal-plugin 2.1.0 bump had
  silently upgraded Sylius 2.2 -> 2.3.2, but the frontend half of the upgrade
  (vendor/sylius/sylius/UPGRADE-2.3.md, "Dependencies" 7/9/10) was never done,
  so the admin ran on stale CSS with dark text in dark mode. Done now: Encore 6,
  @sylius-ui/* from Resources/npm, babel transform plugin, Node 24, webpack
  pinned <5.109 like Sylius.

- Unit tests for all custom code (2026-10-23): 130 tests in tests/Unit, no DB or
  kernel needed, so they run in the required CI check. Controllers use
  tests/Unit/Controller/ControllerTestCase (real forms + session, stubbed Twig/router).
  Writing them found and fixed a bug: /quote never showed "Add at least one item."

- End-to-end tests (2026-10-23): 45 tests in tests/Functional (real kernel,
  templates, MySQL) for every shop page, the contact and RFQ forms (incl.
  emails, CSRF, throttling), sitemap, admin login/branding and the RFQ
  backoffice. New per-PR CI job "End-to-end" (.github/workflows/ci_e2e.yaml).
  Found and fixed: the RFQ migration failed on any fresh database
  (messenger_messages statements), which would have broken a new Upsun env.

- Catalog structure + dynamic sitemap (2026-10-23): `app:catalog:setup-categories`
  creates the six /services categories and sets them as the shop menu (idempotent;
  run once per environment, NOT on deploy). sitemap.xml now lists enabled products
  (with lastmod) and categories that have products. Fixed: Sylius's cart and
  category slide-out titles were <h1>s (two H1s per page); footer links,
  listing labels and info boxes unreadable/teal on the dark theme.

- Product import + go-live (2026-10-23): `app:catalog:import` (xlsx/csv) and
  `app:catalog:import-template`; new products arrive as hidden drafts; re-imports
  respect admin edits per field (app_product_import_state snapshot); "Go live" /
  "Take offline" bulk actions on the Sylius admin product list with a readiness
  check (name, category, price). Pricing = supplier price for now.

- ORS Nasco research (2026-10-24): the "P&A REST API v2.0" (Workato, OAuth) is
  bulk STOCK only, 6 refreshes/day; prices come from the separate SOAP service
  (GetPA, PriceOnlyV2, 40 items/call) and are our account COST, not a sell price.
  Docs in ~/Downloads (both PDFs + the 2,737-SKU price/direct-ship sheets).
- Category tree draft for client review (2026-10-24): 7 departments, 31
  categories, counts from the 2,737 launch SKUs:
  https://claude.ai/artifact/51a7h72mQ1qY3HXwJbemdG
- Short shop URLs (2026-10-24, PR #34): no /en_US prefix, categories at the root
  (/safety-ppe), products at /products/{slug}, old links 301; trademark signs
  out of product slugs.
- ORS Nasco sync (2026-10-26): `app:ors:sync-stock` (bulk REST, on hand = ORS
  total) and `app:ors:sync-cost` (SOAP PriceOnlyV2, cost into
  `app_supplier_item`); go-live refuses products priced at or below cost.
  Dry-run, --sku, nothing written on an ORS failure. Crons committed but
  commented out until credentials exist.
- Stripe only (2026-10-26): removed the Mollie, Adyen, PayPal and Refund
  plugins (code, routes, scripts, tables and columns: migration
  Version20261026150000, irreversible). Stripe API keys are stored encrypted;
  the key lives in an Upsun mount (config/encryption), made on first deploy.
  Shop JS bundle 6.3 MB -> 1.3 MB (dev build).
- Faster shop pages (2026-10-25): Mollie's and Adyen's scripts (~1.9s from
  their servers, blocking) now load only on checkout and order-payment pages.
  Fixed a PR #34 bug: the old-link redirect was catching Mollie/Adyen/PayPal
  addresses, which still start with /en_US.
- Lead emails (2026-10-26): contact messages are now saved (/backoffice →
  Contact Messages, mark handled) as well as RFQs; both email the team from
  MAILER_FROM (no-reply@usiscs.com) with Reply-To = customer and an admin
  link. Sylius's own emails use the same sender (was no-reply@example.com).
  Not live yet: needs a mail provider DSN on Upsun and the channel contact
  email set (see local-setup.md "Lead emails").

- Pack units and supplier SKUs (2026-10-26): `unit_of_measure` ("144 PR = 1
  CA", "case of 12") is stored per variant and shown as "per case of 144
  pairs" under prices and "Sold per ..." in the cart. SKUs with . / + become
  `_` in the product code; the ORS SKU is kept for the syncs.

- Variant groups and the ORS import sheet (2026-10-26): import columns
  `group` + `variant_name` sell several SKUs as one product with a choice;
  `app:catalog:prepare-ors-import` turns the ORS price feed into an import
  sheet (list price, pack unit, families grouped, draft variant names,
  interim categories).

- Catalog polish (2026-10-26): size/colour choices are listed in natural
  order (Size 6 before Size 10); category pages show 24 products (48/96 on
  the Show menu); the import summary counts choices added to an existing
  product separately. The full ORS catalog (1,390 products) is loaded live on
  the local dev DB for browsing.
- Dark theme fixes (2026-10-26): cart summary, coupon box, checkout header
  and summary column, login panel and light buttons no longer render as white
  panels with near-white text (brand.scss maps Bootstrap's light-only classes
  onto the dark panel colour; the logo keeps its white box via .logo-panel).

## Decisions
- ORS Nasco: selling price = ORS list price (from the price feed import); the
  API "price" is our cost, used only as a never-sell-below guard. Stock always
  follows ORS (orders change on-hand, so admin stock edits aren't kept).
- Payments: Stripe only (flux-se/sylius-stripe-plugin). Mollie, Adyen, PayPal
  and the Refund plugin were removed (2026-10-26). Use the "Stripe Checkout"
  gateway (Stripe-hosted card page, smallest PCI scope). Refunds: the payment's
  Refund button in the Sylius admin refunds in full through Stripe; partial
  refunds are done in the Stripe dashboard.
- Short shop URLs, no locale prefix: categories at the root (`/safety-ppe/hand-protection`,
  slugs without the root taxon's "products/"), products at `/products/{slug}`.
  `/en_US/...` links 301 to the new form. The catch-all category route in
  `config/routes.yaml` must stay last, and a category slug must not equal a page
  path (about, contact, cart, account, products...).
  The old-link redirect must stay below plugin routes (no route priority), in
  case a plugin's addresses start with /en_US.
- Catalog import: admin edits always win, per field; imports never change
  whether a product is live (except go_live=yes on brand-new products).
- MySQL 8.4 locally / MariaDB on Upsun; no PostgreSQL
- FOSRestBundle removed: nothing in this app uses it (Sylius 2 uses API Platform)

- Dependabot auto-merge: patch/minor bumps use GitHub auto-merge and only land
  once the required check "Static checks / PHP 8.4, Symfony ~7.4.0" passes
  (branch protection on main, admins can bypass). Majors stay manual.

- Upsun deploy no longer runs `sylius:fixtures:load` (2026-10-23). It used to
  run on every deploy, and the default suite purges the database. For a brand
  new environment, load fixtures once by hand:
  `upsun ssh -- bin/console sylius:fixtures:load -n` (wipes data; never on prod).

## Gotchas
- Dependency bumps can pull in a Sylius minor upgrade. When sylius/sylius
  changes in composer.lock, read vendor/sylius/sylius/UPGRADE-2.x.md and run
  `npm install && npm run build`.
- Renaming the static-checks job or changing the PHP/Symfony matrix renames the
  required check; update branch protection on main to match or PRs will hang.
- Build skips docs-only PRs (paths-ignore), so they never get the required check;
  merge those as admin.
- A local vendor/ that's behind composer.lock can hide CI breakage. Run
  `composer install` after pulling merged dependency bumps.

## Next step
- **Waiting on the client: lead emails** (the code is done). Client is setting
  up Postmark (sent 2026-10-26). Then: verify usiscs.com (SPF/DKIM DNS
  records), set `env:MAILER_DSN` on Upsun, set the channel contact email in
  the Sylius admin, send a test quote.
- **ORS sync: merged (PR #37)**, waiting on ORS credentials (see below).
- **Client review of the category tree** (link above). Then build it:
  replace the 6 placeholder categories (setup-categories), brand pages and
  filters, per-category SEO title/description/intro.
- **Full catalog load (2,737 ORS SKUs)** from the "ORS price feed" sheet in
  `USISCS_ORS_vs_Grainger_Price_Comparison.xlsx` (list price, cost, brand,
  part #, pack/UOM), mapped onto the approved tree.
  - Done: pack unit on prices and in the cart; SKUs with . / + (91 of them);
    size/colour variants grouped into one product; `app:catalog:prepare-ors-import`
    builds the import sheet (2,549 SKUs → 1,390 products, 188 without a price).
  - Still to do: review the 1,684 drafted variant names (some ORS
    abbreviations remain), real categories once the tree is approved.
  - Product images: none in ORS files, so a source is needed.
  - Selling price = ORS **list** price. The ORS API price is our *cost*.
- **ORS sync: built, waiting on ORS credentials.** Get from ORS: OAuth
  client ID/secret + token URL (stock API), and the SOAP account # + 2
  passwords (price service). Then: dry runs against the ORS test endpoints
  (docs/local-setup.md "ORS Nasco sync"), set Upsun variables, uncomment the
  crons in `.upsun/config.yaml`.
- Local dev DB has 60 sample ORS products (imported from the price feed sheet).
- Backoffice upload page so staff can import without the CLI.
- **Turn on Stripe** (merged in PR #38): needs the client's Stripe account
  (see docs/local-setup.md "Payments (Stripe)"): publishable key, a restricted
  secret key, and a webhook. Check Sylius admin → Payment methods on
  production and disable anything that isn't Stripe.
- Then: Upsun deploy + switch build to `npm ci`.
- Dependabot #21 (phpstan 2.3.1) stuck waiting on rebase.
