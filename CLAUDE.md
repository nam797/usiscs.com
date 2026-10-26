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
- Contact form: `src/Form/Contact/`
- ORS Nasco supplier sync: API clients in `src/Supplier/OrsNasco/`, syncs in
  `src/Catalog/Sync/`, commands `app:ors:sync-stock` / `app:ors:sync-cost`
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
