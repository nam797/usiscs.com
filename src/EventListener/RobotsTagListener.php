<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Tells search engines which shop pages to index, with an X-Robots-Tag
 * header. Only the pages worth finding are indexable: home, categories,
 * products and the information pages. Everything else (cart, checkout,
 * account, login, orders, reviews, live-component and payment endpoints,
 * any page added later) gets "noindex, nofollow", so a new private page
 * is never indexed by accident.
 *
 * A category page re-sorted, resized (?limit=) or searched gets "noindex,
 * follow": the same products as the plain page, so only that one should be
 * indexed, but its product links still count. Its canonical points at the
 * plain page too (seo_canonical_url()). Plain pagination (?page=2) stays
 * indexable.
 *
 * public/robots.txt also keeps crawlers out of the private areas.
 */
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse')]
final class RobotsTagListener
{
    /** Routes whose pages may appear in search results. */
    public const INDEXABLE = [
        'sylius_shop_homepage',
        'sylius_shop_product_index',
        'sylius_shop_product_show',
        'app_shop_about',
        'app_shop_services',
        'app_shop_contact',
        'app_shop_rfq',
        'app_shop_privacy',
        'app_shop_terms',
    ];

    /** Query parameters that only re-arrange a category page. */
    public const LISTING_VARIANTS = ['sorting', 'limit', 'criteria'];

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        // Left alone when something already decided (Symfony's dev-mode noindex, for one).
        if ($response->headers->has('X-Robots-Tag') || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'html')) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $route = is_string($route) ? $route : '';

        $tag = match (true) {
            !in_array($route, self::INDEXABLE, true) => 'noindex, nofollow',
            $response->getStatusCode() >= 400 => 'noindex',
            'sylius_shop_product_index' === $route && [] !== array_intersect(self::LISTING_VARIANTS, $request->query->keys()) => 'noindex, follow',
            default => null,
        };

        if (null !== $tag) {
            $response->headers->set('X-Robots-Tag', $tag);
        }
    }
}
