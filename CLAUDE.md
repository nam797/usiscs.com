# usiscs.com

Storefront for usiscs.com (industrial and construction supply), built on
Sylius 2.3 / Symfony 7, PHP 8.4, MySQL 8.4. Hosted on Upsun (MariaDB 11.8 there).
GitHub: nam797/usiscs.com

## Start here
- `docs/progress.md` — what's done, decisions, and the next step. Read it first.
  Update it at the end of every session.
- `docs/local-setup.md` — verified local setup. Run console commands with
  `php -d memory_limit=-1 bin/console ...` (the container won't build at 128M).

## Custom code (everything else is stock Sylius)
- Shop pages: `src/Controller/Shop/` — About, Services, Contact, RFQ, Privacy, Terms, Sitemap
- RFQ (request for quote): `src/Entity/Rfq`, `src/Form/Rfq`, `src/Repository/Rfq`,
  admin CRUD in `src/Controller/Admin/RfqRequestCrudController.php`
- Contact form: `src/Form/Contact/`, saved as `src/Entity/Contact/ContactMessage`,
  admin list in `src/Controller/Admin/ContactMessageCrudController.php`
- Lead emails (RFQ + contact): `src/Lead/LeadNotifier.php`
- Emails: branded frame `templates/emails/layout.html.twig` (Sylius's via
  `templates/bundles/SyliusCoreBundle/Email/`), redirect to itsupport@ outside
  production and the sent-email log + Postmark webhooks in `src/Mail/`;
  see docs/local-setup.md "Emails"
- Catalog import: `src/Catalog/Import/` (`app:catalog:import`), pack units in
  `src/Catalog/Unit/`, ORS price feed → import sheet in `src/Catalog/Ors/`
  (`app:catalog:prepare-ors-import`)
- ORS Nasco supplier sync: API clients in `src/Supplier/OrsNasco/`, syncs in
  `src/Catalog/Sync/`, commands `app:ors:sync-stock` / `app:ors:sync-cost`
- Distributor orders (paid order → purchase orders → queued to ORS etc.):
  `src/Distributor/` (one adapter per distributor), entities in
  `src/Entity/Procurement/`, state machine in `config/packages/workflow.yaml`,
  worker `messenger:consume distributor`; see docs/local-setup.md
- Payments: Stripe only (flux-se/sylius-stripe-plugin, set up in the Sylius
  admin; see docs/local-setup.md "Payments (Stripe)")
- Spam/abuse: `src/RateLimiting/FormSubmissionThrottle.php`
- Security headers: `src/EventListener/SecurityHeadersListener.php`
- Templates: `templates/shop/`, overrides in `templates/bundles/SyliusShopBundle/`

## Rules
- MySQL only. Never switch to PostgreSQL.
- Secrets go in `.env.local` (gitignored), never in `.env`.
- Schema changes go through Doctrine migrations in `migrations/`.
- Run tests (`vendor/bin/phpunit`) before committing.

## Agents for this project
Backend Architect, Code Reviewer, Payments & Billing Engineer, SEO Specialist,
AI-Generated Code Security Auditor (in `.claude/agents/`, local only).
