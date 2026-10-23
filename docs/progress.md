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

## Decisions
- MySQL 8.4 locally / MariaDB on Upsun; no PostgreSQL
- FOSRestBundle removed: nothing in this app uses it (Sylius 2 uses API Platform)

- Dependabot auto-merge: patch/minor bumps use GitHub auto-merge and only land
  once the required check "Static checks / PHP 8.4, Symfony ~7.4.0" passes
  (branch protection on main, admins can bypass). Majors stay manual.

## Gotchas
- Renaming the static-checks job or changing the PHP/Symfony matrix renames the
  required check; update branch protection on main to match or PRs will hang.
- Build skips docs-only PRs (paths-ignore), so they never get the required check;
  merge those as admin.
- A local vendor/ that's behind composer.lock can hide CI breakage. Run
  `composer install` after pulling merged dependency bumps.

## Next step
- Dependabot #21 (phpstan 2.3.1): waiting on rebase; fix any new phpstan errors
- Then: catalog/products (+ sitemap TODO), choose Stripe vs Adyen, verify Upsun deploy
