<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A minimal, hand-maintained sitemap.xml for the pages that exist today.
 * There's no catalog yet, so there's nothing to generate from Sylius's
 * product/taxon repositories -- once products exist, this should switch
 * to pulling product/taxon URLs dynamically rather than staying a static
 * list (see the TODO below).
 */
final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_shop_sitemap', format: 'xml')]
    public function index(): Response
    {
        // TODO once products/taxons exist: loop them in here (or switch to
        // a dedicated sitemap bundle) instead of hand-listing routes.
        $routes = [
            ['name' => 'sylius_shop_homepage', 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['name' => 'app_shop_services', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'app_shop_about', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'app_shop_contact', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'app_shop_rfq', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'app_shop_terms', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['name' => 'app_shop_privacy', 'changefreq' => 'yearly', 'priority' => '0.3'],
        ];

        return $this->render('shop/sitemap/index.xml.twig', [
            'routes' => $routes,
        ], new Response('', 200, ['Content-Type' => 'application/xml']));
    }
}
