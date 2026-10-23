# usiscs.com progress

## Done
- Sylius 2.2 storefront set up; local setup verified on macOS (2026-10-19)
- Custom shop pages: About, Services, Contact, RFQ, Privacy, Terms, Sitemap
- RFQ system with admin review screen; form rate limiting; security headers
- Dependabot updates merged (latest 2026-10-22)
- CI fixed (2026-10-23): main had been red since the sylius/paypal-plugin 2.1.0
  bump dropped friendsofsymfony/rest-bundle while config/bundles.php still
  registered it. Also fixed 5 phpstan errors that slipped in while CI was red.

## Decisions
- MySQL 8.4 locally / MariaDB on Upsun; no PostgreSQL
- FOSRestBundle removed: nothing in this app uses it (Sylius 2 uses API Platform)

## Gotchas
- A local vendor/ that's behind composer.lock can hide CI breakage. Run
  `composer install` after pulling merged dependency bumps.

## Next step
- Fix .github/workflows/auto-merge.yml (fails: "Input required and not
  supplied: github-token"), and only auto-merge after CI passes, so bumps
  can't land on a red build again
- Re-run and merge Dependabot PRs #21 (phpstan) and #22 (suite-settings-extension)
- Then: catalog/products (+ sitemap TODO), choose Stripe vs Adyen, verify Upsun deploy
