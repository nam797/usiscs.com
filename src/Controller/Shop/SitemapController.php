<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Sitemap\CatalogUrlSourceInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

/**
 * sitemap.xml: the hand-listed content pages, plus every enabled product
 * and every category that has products in the current channel.
 *
 * One file is fine up to the sitemap protocol's 50,000-URL limit; past
 * that this needs to become a sitemap index split across several files.
 */
final class SitemapController extends AbstractController
{
    private const PAGES = [
        ['name' => 'sylius_shop_homepage', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['name' => 'app_shop_services', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['name' => 'app_shop_about', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ['name' => 'app_shop_contact', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ['name' => 'app_shop_rfq', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['name' => 'app_shop_terms', 'changefreq' => 'yearly', 'priority' => '0.3'],
        ['name' => 'app_shop_privacy', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ];

    public function __construct(
        private readonly CatalogUrlSourceInterface $catalog,
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
    ) {
    }

    #[Route('/sitemap.xml', name: 'app_shop_sitemap', format: 'xml')]
    public function index(): Response
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);
        $locale = $this->localeContext->getLocaleCode();

        $routes = [];

        foreach (self::PAGES as $page) {
            $routes[] = $page + ['parameters' => [], 'lastmod' => null];
        }

        foreach ($this->catalog->taxonSlugs($channel, $locale) as $slug) {
            $routes[] = [
                'name' => 'sylius_shop_product_index',
                'parameters' => ['_locale' => $locale, 'slug' => $slug],
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'lastmod' => null,
            ];
        }

        foreach ($this->catalog->products($channel, $locale) as $product) {
            $routes[] = [
                'name' => 'sylius_shop_product_show',
                'parameters' => ['_locale' => $locale, 'slug' => $product['slug']],
                'changefreq' => 'weekly',
                'priority' => '0.6',
                'lastmod' => $product['updatedAt']?->format('Y-m-d'),
            ];
        }

        return $this->render('shop/sitemap/index.xml.twig', [
            'routes' => $routes,
        ], new Response('', 200, ['Content-Type' => 'application/xml']));
    }
}
