<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shop URLs used to start with the locale and put categories under /taxons:
 * /en_US/taxons/products/safety-ppe, /en_US/products/hex-bolt, /en_US/cart/.
 * They now live at /safety-ppe, /products/hex-bolt, /cart/. Old links
 * (bookmarks, shared demo links, search results) are sent to the new address.
 */
final class LegacyUrlRedirectController
{
    #[Route(
        '/{locale}/{path}',
        name: 'app_shop_legacy_locale_redirect',
        requirements: ['locale' => '[a-z]{2}_[A-Z]{2}', 'path' => '.*'],
        defaults: ['path' => ''],
        methods: ['GET', 'HEAD'],
    )]
    public function __invoke(Request $request, string $path): RedirectResponse
    {
        if (str_starts_with($path, 'taxons/')) {
            // Category slugs also lost the root taxon's "products/" prefix.
            $path = (string) preg_replace('#^taxons/(products/)?#', '', $path);
        }

        $query = $request->getQueryString();

        return new RedirectResponse('/'.$path.(null === $query ? '' : '?'.$query), 301);
    }
}
