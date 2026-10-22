<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Adds the handful of response headers that cost nothing to turn on and
 * have no visible effect on this site's own behavior -- unlike a real
 * Content-Security-Policy, which needs this app's actual script/style/
 * image sources worked out deliberately (inline <script>/<style> here,
 * Stripe's checkout iframe, any CDN fonts) before it can be turned on
 * without breaking something. That's left as a follow-up; see the open
 * question in the project doc.
 *
 * Kept as a hand-rolled listener rather than reaching for a bundle (e.g.
 * nelmio/security-bundle): nothing here needs config beyond "always send
 * these headers," and no new composer dependency was installable
 * from the environment this was written in anyway.
 */
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse')]
final class SecurityHeadersListener
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        // X-Frame-Options is deliberately NOT set here: Sylius's own
        // Sylius\Bundle\CoreBundle\EventListener\XFrameOptionsSubscriber
        // already sends `sameorigin` on every response (confirmed by
        // checking the actual response headers -- it fires after this
        // listener and unconditionally overwrites whatever this sets, so
        // adding a competing value here would be dead code at best).
        // `sameorigin` already rules out the clickjacking threat this
        // site actually has (no embed/widget use case), so there's
        // nothing to improve on here.

        // Stops browsers from "helpfully" re-sniffing a response's
        // declared Content-Type (e.g. treating an uploaded file as HTML).
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        // Send the referring page's full URL only to this site's own
        // origin; drop it entirely on cross-origin requests so query
        // strings on internal pages (e.g. an /admin search) never leak
        // to a third-party link a page links out to.
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // HSTS only makes sense once the site is actually served over
        // HTTPS -- sending it over local plain-HTTP dev would tell real
        // browsers to force HTTPS for this host, including on localhost,
        // which breaks `symfony server:start` without TLS. `$this->kernel
        // ->isDebug()` keeps it off for dev/test and on wherever
        // APP_ENV=prod (Upsun), independent of whatever scheme the
        // current request happens to be.
        if (!$this->kernel->isDebug() && !$headers->has('Strict-Transport-Security')) {
            // 6 months, include subdomains, no preload yet (preload is a
            // one-way, slow-to-reverse commitment -- opt in deliberately
            // once the domain's HTTPS setup has proven itself).
            $headers->set('Strict-Transport-Security', 'max-age=15768000; includeSubDomains');
        }
    }
}
