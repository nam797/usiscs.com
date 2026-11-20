<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Catalog\Category\CategoryTree;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The six launch categories were replaced by the approved tree
 * (2026-11-20). Their old pages (/mro-materials, ...) send visitors and
 * search engines to the closest new page (CategoryTree::RETIRED).
 */
final class RetiredCategoryRedirectController
{
    #[Route(
        '/{slug}',
        name: 'app_shop_retired_category_redirect',
        requirements: ['slug' => 'mro-materials|electrical-controls|maintenance-supplies|fasteners-hardware|specialty-hard-to-find'],
        methods: ['GET', 'HEAD'],
        priority: 10,
    )]
    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        $target = '/';
        foreach (CategoryTree::RETIRED as [$oldSlug, $newPath]) {
            if ($oldSlug === $slug) {
                $target = $newPath;
            }
        }
        $query = $request->getQueryString();

        return new RedirectResponse($target.(null === $query ? '' : '?'.$query), 301);
    }
}
