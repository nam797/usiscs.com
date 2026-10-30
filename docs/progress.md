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
- Fulfilment per SKU (2026-10-27): ORS direct / cross-dock (ORS ships to our
  warehouse, we ship on) / not confirmed, plus a shipping note. Import columns
  `fulfilment`, `shipping_note`; admin product and variant forms; admin list
  filter. Go live refuses a product while any SKU is not confirmed. The ORS
  import sheet fills it from the direct-ship review (blank so far) and notes
  the 306 priced SKUs its screen flagged.
- Test shipping method (2026-10-27): `checkout_shipping` fixture suite
  (dev only, config/packages/app_dev_fixtures.yaml) adds the US country +
  zone and a $15 flat-rate "Standard Shipping" on the default channel, so
  checkout can be tested locally. Loaded into the local DB. Not real rates.
- Distributor orders, part 1 (2026-10-27): a paid order becomes purchase
  orders, one per distributor + fulfilment (`src/Distributor/`).
  - Patterns: one adapter per distributor (DistributorInterface); the
    purchase order row is the outbox; sending goes through a Messenger
    Doctrine queue with ~2 days of backoff; a state machine
    (`config/packages/workflow.yaml`); an append-only event timeline.
  - Never sent twice: each retry first asks the distributor if it already
    has the PO. Failures end in "needs attention" plus an email to staff.
  - ORS runs in manual mode (no order API): staff are emailed to place it,
    then Mark submitted.
  - Backoffice: Purchase Orders (timeline, Retry / Mark submitted / Cancel,
    fix distributor/fulfilment) and Distributors (pause, manual entry). The
    Sylius order page shows the order's purchase orders.
  - New `distributor` on variants (imports and existing ORS SKUs:
    ors_nasco).
  - Upsun: a `distributor-queue` worker and an `app:distributor:dispatch`
    cron every 15 min.
  - Dev/test-only "Test distributor" (FAKE_DISTRIBUTOR_MODE).
  - Found while building this: the ORS doc we have is the Order *Query*
    service only (look up status, tracking, invoice by our PO number).
    There's nothing for placing orders.
- Distributor orders, part 2 (2026-10-27): order tracking.
  - `app:distributor:sync-orders` (cron every 30 min) asks each distributor
    for its open purchase orders' status. For ORS that's the Order Query
    SOAP service by our PO number (`OrderQueryClient`); ORS's split
    suffixes are mapped onto one status (`OrsOrderStatusMapper`).
  - Recorded on the timeline: acknowledged, on hold, back-orders, shipped
    quantities, tracking numbers, invoice totals (freight, surcharges).
  - Orders staff placed by hand are found and marked submitted. An ORS
    cancellation goes to "needs attention" with an email.
  - Direct orders: the tracking number goes on the customer's shipment, and
    once everything has shipped the shipment is marked shipped and Sylius's
    shipping email is sent.
  - A watchdog emails staff one list of stuck purchase orders, at most
    daily each.
  - The ORS client is built from the PDF's examples and isn't tested against
    ORS yet. How ORS answers an unknown PO is a guess (an empty result, or an
    error saying "not found").
- Cross-dock warehouse (2026-10-27): it's now clear which orders go through
  our warehouse, and when they arrive.
  - Backoffice → **Warehouse** (`WarehouseCrudController`): cross-dock
    purchase orders, with quick links for Coming in, Due in the next 7 days,
    Overdue, and Received, to ship on.
    - Each shows the items, who it ships to, when the distributor shipped it
      (and its tracking), the expected date and a warehouse status.
    - Actions: Set expected date → Mark received (with a note) → Ship to
      customer (our carrier + tracking).
  - Expected date = the distributor's first tracking + 3 business days
    (`app.cross_dock_transit_days`); staff can change it. ORS gives no
    delivery date.
  - Ship to customer puts our tracking on the customer's order. Mixed
    orders (some direct, some cross-dock) are marked shipped and emailed
    only once every part has gone.
  - Sylius admin order list: a Delivery column and filter (Direct /
    Cross-dock / Mixed / Not confirmed), stored on the order when it's paid
    (`sylius_order.fulfilment_route`; existing orders backfilled by the
    migration). The order page shows each purchase order's warehouse status.
  - Purchase Orders: a fulfilment filter and badge.
  - `/backoffice` now opens on a real dashboard (`BackofficeSummary`), not
    EasyAdmin's welcome page. It shows:
    - warehouse tiles: overdue, due in the next 7 days, on its way, received to ship on
    - Coming in (soonest first) and Received, to ship on (each row has a Ship to customer button)
    - purchase orders needing attention, awaiting manual entry or on hold
    - new quote requests and new contact messages

    Every number links to its filtered list.
  - `app:dev:demo-cross-dock` (dev only) loads sample DEMO orders in every
    warehouse state. They're loaded into the local dev DB.
  - Fixed (2026-10-28, PR #48): a delivery's page in Warehouse crashed in
    dev ("Variable "ea" does not exist"). The tests missed it because Twig
    wasn't strict about undefined variables in tests (APP_DEBUG=0). It's
    strict in the test env now.
- Several distributors per product (2026-10-28): a SKU has **sources**
  (`app_supplier_item`, one per distributor). Each source has the
  distributor's SKU, fulfilment, cost, stock and an on/off switch.
  - `distributor` and `fulfilment` moved off the variant onto its sources.
    The migration made one ORS source per SKU (2,549 locally) and is
    reversible.
  - **Routing:** each paid order line goes to the cheapest switched-on
    source with enough stock (`SourceSelector`). The purchase order's
    timeline shows the comparison. Rules:
    - never at or below cost
    - unknown stock ranks after known stock
    - a line is never split
    - if nobody has enough stock, the cheapest takes it as a back-order
  - **Shop stock** = the total over switched-on sources
    (`VariantStockTotal`). ORS's stock and cost syncs only write ORS's
    source.
  - **Import:** new `distributor` (code or name, ORS when blank) and
    `supplier_sku` columns. A row for a SKU another distributor's sheet
    created only adds or updates that distributor's source. The product's
    name, price and description stay with the first sheet
    (`app_product_import_state.distributor`).
  - **Admin:** the variant and product forms list the sources. Backoffice →
    Sources edits them (fulfilment, on/off, cost and stock for a
    distributor without a feed).
  - **Go live** needs a switched-on source with confirmed fulfilment, and a
    price above every switched-on source's known cost.
  - `app:dev:demo-cross-dock` adds DEMO-007, routed between two sample
    products' ORS and Test distributor sources.

- Category search box (2026-10-28): the product search on category pages
  said "Value" (Sylius used the field's label as the placeholder). It now
  says "Search this category", also as its aria-label
  (`templates/bundles/SyliusShopBundle/grid/filter/string.html.twig`).
- Breadcrumbs (2026-10-28): "Products" (the root category) is now a link
  to /products, the page listing the whole catalog, on category and product
  pages. Sylius showed the root as plain text (overrides in
  `templates/bundles/SyliusShopBundle/product/`).

- Strong passwords (2026-10-28): every place a password is set needs at
  least 12 characters that aren't easy to guess (Symfony PasswordStrength
  "medium"). That covers shop sign-up, change password, reset password, the
  same three in the API, and admin users. Sylius only asked for 4.
  - Rule: `App\Validator\StrongPassword`, applied in
    `config/validator/password.yaml`.
  - A hint under the new-password field says what's needed.
  - Existing passwords still work; the rule applies when one is set.

- SEO, first batch (2026-10-28), from the SEO Specialist's review:
  - **One canonical address per page.** The canonical tag and `og:url`
    drop sorting, `?limit=` and search parameters, keeping `?page=N`
    (`seo_canonical_url()` in `App\Twig\SeoExtension`).
  - **Index only the pages worth finding** (`App\EventListener\RobotsTagListener`):
    - Indexable: home, categories, products and the information pages.
    - Re-sorted, resized or searched category pages: `noindex, follow`.
    - Everything else (cart, checkout, account, login, register, orders,
      review pages, payment and component endpoints): `noindex, nofollow`.
    - `robots.txt` also disallows those areas and the sorting, limit and
      search parameters.
  - **Product pages** get their own meta description: the product's
    meta description, short description or description; otherwise one
    built from name, part number, price and pack unit. They also get
    `og:type=product` and price tags.
  - **Hidden until there's real content:** the empty review UI (stars,
    "0 reviews", "Add your review", the Reviews section) and the "Details"
    section when it would only say "No description".
  - **Sitemap** lists a category when it has products in it or anywhere
    below it, so `/products` and the coming department pages are included.
  - Still to do (after batch 2 below): canonical/sitemap host from the channel or https behind Upsun,
    image alt text and placeholders, internal links, shorter
    descriptions, `og:image`, homepage title copy (needs client sign-off),
    product titles with brand and part number, the footer link to
    sylius.com, and lazy-loaded images.

- SEO, second batch (2026-10-28):
  - **Category pages have their own titles and descriptions**
    (`App\Twig\CategorySeoExtension`):
    - Title: "Safety & PPE | US Industrial Supply Chain Solutions", with
      "– Page N" from page 2. `/products` is "All products".
    - Meta description: the category's own description when it has one.
      Otherwise it's built from the product count, top brands and
      subcategories, e.g. "Safety & PPE: 403 products from brands like 3M™,
      MCR SAFETY and SHOWA®…".
  - **Structured data, JSON-LD** (`App\Twig\StructuredDataExtension`):
    - Product pages: Product with Offer (price, currency, availability), or
      AggregateOffer when it comes in several sizes or colours; brand and
      part number from the attributes. No ratings, since there are no
      reviews.
    - Category and product pages: BreadcrumbList.
    - Homepage: Organization (logo, phone) and WebSite.
    - The JSON escapes `<`, `>` and `&`, so catalog text can't break out of
      the script tag.
  - **Fixed:** `&` in a title or description was escaped twice in the meta
    and Open Graph tags ("Safety &amp;amp; PPE"). The meta-description
    block also no longer prints inside an HTML comment.

- Checkout and account fixed (2026-10-28): Sylius's
  `sylius.security.shop_regex` assumed a `/en_US` prefix, which the short
  URLs (PR #34) removed. Two things broke:
  - The checkout address step crashed ("route sylius_order_index does not
    exist") because the redirect to the next step never ran.
  - `/account` pages weren't login-protected: guests got a 500 instead of
    the login page.

  Now overridden in `config/packages/sylius_shop.yaml` with the same
  exclusions and no prefix. `tests/Functional/Shop/CheckoutTest.php` covers
  both.

- Card payments held until shipping (2026-10-28): the Stripe payment
  method authorizes at checkout ("use authorize" on), so Stripe holds the
  money.
  - **Purchase orders go out on the authorization**
    (`PlanPurchaseOrdersOnPayment` also listens to the order's `authorize`).
  - **Charged** (`App\Distributor\Payment\AuthorizedPaymentHandler`) when
    the first purchase order ships: partly, fully, or straight to invoiced.
    That's `SettleAuthorizedPayment`, on `PurchaseOrderTransitioned`, so it
    lands on the timeline after the move. The whole amount is charged once.
  - **Charged anyway** when it's still held after
    `app.capture_authorized_after_days` (6), by `app:payments:capture-due`
    (an Upsun cron, hourly at :15). Stripe releases holds at about 7 days,
    and ORS can't report shipments yet.
  - **Released** when every purchase order of the order is cancelled.
  - **If Stripe refuses:** nothing is saved, the hold stays, the purchase
    order's timeline says why, and staff are emailed.
  - Each capture and release is on the purchase orders' timelines.
  - So are changes made by hand (`LogPaymentOnPurchaseOrders`): Complete
    on a held payment in the admin, cancelling it, and refunds (the admin's
    Refund button, or Stripe's refund webhook). Each says who did it.

- Customer-journey test pass (2026-10-29):
  `tests/Functional/Shop/CustomerJourneyTest.php` walks every customer
  journey end to end at the real URLs. The add-to-cart and cart buttons
  are live components, posted to as the page's JavaScript would.
  - Journeys covered:
    - a guest buys from a product page to the thank-you page and opens
      the order page by its link
    - a logged-in customer checks out, finds the order in their account,
      and the address is saved
    - sign up, verify, log in and out
    - forgotten password, then reset
    - every account page, profile edit and address book add
    - cart quantity change and remove
    - the 404 page
  - All passed except one thing, now fixed: the account forms (sign-up,
    forgotten and reset password, change password, address book) never
    showed whole-form errors. An expired form (old CSRF token after a long
    pause) just came back blank with no message. They now show it at the
    top (`templates/shop/form/form_errors.html.twig`, hooked into each
    form).
  - Found for the deploy: `DEFAULT_URI` wasn't set on Upsun, so links in
    emails sent from crons and the worker would have pointed at
    `http://localhost`. That includes the customer's "your order has
    shipped" email. `.environment` now sets it from Upsun's primary route
    (see Next step).
  - Not covered by tests: the mobile layout and how pages look. Check by
    eye on staging.

## Decisions
- Passwords: at least 12 characters and not easy to guess. Not yet
  checked against known breached passwords (Symfony's
  NotCompromisedPassword asks the haveibeenpwned API); decide before
  launch.
- Payments: cards are authorized at checkout and charged when the first
  purchase order ships, or on day 6 at the latest, for the whole order.
  When every purchase order is cancelled, the hold is released instead.
  If one part is cancelled after the charge, refund that part by hand
  (the payment's Refund button refunds in full; partial refunds in
  Stripe).
- Several distributors per SKU: orders go to the cheapest switched-on
  source that has the stock, never at or below cost, never split. The
  first distributor's sheet owns the product's details; later sheets only
  add their source.
- Nothing goes live until its shipping is confirmed: every SKU needs a
  fulfilment (ORS direct or cross-dock). ORS hasn't said which yet (the
  direct-ship review columns are blank for all 2,737).
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
- Distributor orders: a purchase order is created only once the order is
  fully paid, and only once per order. Orders with no distributor or an
  unconfirmed fulfilment are never guessed: they go to "needs attention".
  Our PO number `{order}-{n}` is the distributor's customer PO number (ORS
  "custpo"), so we can look an order up by it.
- Cross-dock warehouse steps (expected, received, shipped to customer) are
  fields on the purchase order, not states of its state machine: the
  distributor's side (shipped → invoiced) moves on independently. The
  warehouse status is worked out from them (`WarehouseStatus`, same rule
  in PHP and in the list filter), never stored. A cross-dock purchase
  order's distributor tracking is the parcel to our warehouse and is never
  shown to the customer.

- Dependabot auto-merge: patch/minor bumps use GitHub auto-merge and only land
  once the required check "Static checks / PHP 8.4, Symfony ~7.4.0" passes
  (branch protection on main, admins can bypass). Majors stay manual.

- Upsun deploy no longer runs `sylius:fixtures:load` (2026-10-23). It used to
  run on every deploy, and the default suite purges the database. For a brand
  new environment, load fixtures once by hand:
  `upsun ssh -- bin/console sylius:fixtures:load -n` (wipes data; never on prod).

## Gotchas
- Tests run with APP_DEBUG=0, so Twig doesn't recompile changed templates
  in the test env. After editing a template, `rm -rf var/cache/test`
  before running phpunit, or the tests use the old version.
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
- **On the first Upsun deploy, check `DEFAULT_URI`:** `.environment`
  (sourced by Upsun before the app, crons and workers) sets it from the
  environment's primary route: `https://usiscs.com` on production, the
  environment's own URL on staging. A `DEFAULT_URI` Upsun variable wins.
  Check with `upsun ssh -- 'echo $DEFAULT_URI'`. Emails sent from crons and
  the worker take their links from it, including the customer's shipped
  email.
- **Stripe test checkout:** the client's Stripe account is set up locally
  (payment method `stripe`, "use authorize" on). Test order #000000001 went
  through: authorized at checkout, then captured from the admin, which
  created its purchase order (needs attention: fulfilment not confirmed).
  Next, test the new flow end to end: a new order should get its purchase
  order at checkout, and be charged when it ships or after 6 days. Webhooks
  (customer closes the tab, refunds from the Stripe dashboard) need the
  Stripe CLI, which needs the client to enable CLI access.
- **SEO batch 2 (merged, PR #55):** check the pages with Google's Rich
  Results Test once there's a public URL. Then the rest of the review list
  under "SEO, first batch" in Done.
- **Cross-dock warehouse (merged, PR #46):** ask the client for the
  warehouse address and a realistic ORS transit time to the warehouse (the
  default is 3 business days), and whether the warehouse should get an
  email when a delivery is due.
- **Distributor orders, next:**
  - Ask ORS for the Order Query web service passwords (`ORS_OQ_PASSWORD`,
    `ORS_OQ_PASSWORD2`), then run `app:distributor:sync-orders -v` against
    their TEST endpoint. Check: an unknown PO, a held order, a split order.
  - Ask ORS how they take orders (an order-entry API, EDI 850, punchout).
    Part 3 is ORS submit, once they answer.
  - Also to decide with the client: our warehouse address for cross-dock,
    blind drop-ship paperwork, shipping method → ORS ShipVia mapping,
    back-order/cancel policy, a cost guard at submit, and a customer PO
    field at checkout.
  - On Upsun, check the worker's resources after the first deploy.
- **Real shipping rates** (decide with the client): the only method is the
  $15 flat-rate test one, local only. Check production has a shipping method
  and a US zone (Sylius admin → Shipping methods); checkout can't finish
  without one.
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
- **Upsun deploy** (2026-10-30): project `xvkiv2v7bwa7s` ("usiscs.com",
  region us-3, org us-industrial-supply-chain), with GitHub integration on
  nam797/usiscs.com. Upsun's `main` environment builds from GitHub `main`;
  other branches show up as inactive environments. PR environments are off.
  - First `main` deploy: built and migrated, but every page is a 500
    ("Channel could not be found"). The database was empty.
  - Local dev DB copied in (2026-10-30). It's mysqldump with
    `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` for MariaDB 11.8.6.
    Now the shop, sitemap and admin login work at
    https://main-bvxea6i-xvkiv2v7bwa7s.us-3.platformsh.site.
    - Canonical links, `og:url` and the sitemap come out as https, so no
      trusted-proxy setup is needed.
    - The copy brought test data: orders 000000001/2 and DEMO-001..007, the
      8 test customers, and the $15 test shipping method. Clean it out
      before launch.
    - Stripe: the gateway's keys were encrypted with the local key, which
      Upsun doesn't have. Re-enter them in the admin (Payment methods →
      Stripe) on Upsun.
  - Fixed on `upsun-prep`:
    - The build uses `npm ci`. Yarn ran with no lockfile.
    - The prod app cache pool (`var/share`, Symfony 7.4's share dir) was
      read-only, so the form throttle couldn't save counts. It's now a
      storage mount.
    - Prod sessions go in the `var/sessions` mount, so they survive
      deploys.
    - Product pages jumped while loading. The main image had no size, so
      the page drew without it, then pushed everything down. Every asset
      was also `no-cache`, so each page view re-checked the CSS, JS and
      images. Fixed with `#main-image` aspect-ratio 4:3 (brand.scss),
      `/build` cached for a year (hashed names) and `/bundles` for a day.
      Filmed in headless Chromium and WebKit: no unstyled frame, only the
      jump. Firefox wouldn't launch in the sandbox.
  - Upsun's CLI PHP has no memory limit (-1), so the container compiles
    fine there.
  - Resources: `sylius` 0.5 CPU / 224 MB, worker 0.5 / 224, db 0.5 / 1408.
    Check page speed once there's data.
  - Staging (2026-10-30): a long-lived `staging` branch is the Upsun
    staging environment. Feature PRs go into `staging`, and releases are
    `staging` → `main` PRs (docs/local-setup.md "Hosting (Upsun)").
    `bin/prod-only` skips the order-handling crons and the purchase order
    worker outside production (`APP_RUN_JOBS=1` overrides it).
  - Next: re-enter the Stripe keys and clean the test data. Then add the
    usiscs.com domain (`upsun domain:add`) and DNS.
- Dependabot #21 (phpstan 2.3.1) stuck waiting on rebase.
