<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Shop;

use App\Controller\Shop\SitemapController;
use App\Sitemap\CatalogUrlSourceInterface;
use App\Tests\Unit\Controller\ControllerTestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapControllerTest extends ControllerTestCase
{
    private const STATIC_PAGES = [
        'sylius_shop_homepage',
        'app_shop_services',
        'app_shop_about',
        'app_shop_contact',
        'app_shop_rfq',
        'app_shop_terms',
        'app_shop_privacy',
    ];

    public function testItIsServedAsXml(): void
    {
        $response = $this->sitemap();

        self::assertSame(200, $response[0]);
        self::assertSame('application/xml', $response[1]);
        self::assertSame(['shop/sitemap/index.xml.twig'], $this->renderedTemplates());
    }

    public function testWithAnEmptyCatalogItListsJustTheContentPages(): void
    {
        $this->sitemap();

        self::assertSame(self::STATIC_PAGES, array_column($this->routes(), 'name'));
    }

    public function testCategoriesAndProductsFollowTheContentPages(): void
    {
        $updated = new \DateTimeImmutable('2026-10-20 14:30:00');

        $this->sitemap(
            taxonSlugs: ['safety-ppe'],
            products: [
                ['slug' => 'safety-glasses', 'updatedAt' => $updated],
                ['slug' => 'hard-hat', 'updatedAt' => null],
            ],
        );

        $routes = $this->routes();
        self::assertSame(
            [...self::STATIC_PAGES, 'sylius_shop_product_index', 'sylius_shop_product_show', 'sylius_shop_product_show'],
            array_column($routes, 'name'),
        );

        self::assertSame(['slug' => 'safety-ppe'], $routes[7]['parameters']);
        self::assertNull($routes[7]['lastmod']);

        self::assertSame(['slug' => 'safety-glasses'], $routes[8]['parameters']);
        self::assertSame('2026-10-20', $routes[8]['lastmod'], 'Products carry their last-modified date.');

        self::assertSame(['slug' => 'hard-hat'], $routes[9]['parameters']);
        self::assertNull($routes[9]['lastmod']);
    }

    public function testTheCatalogIsQueriedForTheCurrentChannelAndLocale(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $catalog = $this->createMock(CatalogUrlSourceInterface::class);
        $catalog->expects(self::once())->method('products')->with($channel, 'en_US')->willReturn([]);
        $catalog->expects(self::once())->method('taxonSlugs')->with($channel, 'en_US')->willReturn([]);

        $this->sitemap(catalog: $catalog, channel: $channel);
    }

    public function testEveryListedAppRouteActuallyExists(): void
    {
        // Guards against a page's route being renamed without updating the
        // sitemap, which would 500 the whole sitemap.xml on url().
        $this->sitemap();

        $declared = self::declaredShopRouteNames();
        foreach (array_column($this->routes(), 'name') as $name) {
            if (str_starts_with($name, 'sylius_shop_')) {
                continue;
            }
            self::assertContains($name, $declared);
        }
    }

    public function testEveryEntryHasAValidChangefreqAndPriority(): void
    {
        $this->sitemap(taxonSlugs: ['x'], products: [['slug' => 'y', 'updatedAt' => null]]);

        foreach ($this->routes() as $route) {
            self::assertContains($route['changefreq'], ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']);
            self::assertMatchesRegularExpression('/^(0\.\d|1\.0)$/', $route['priority']);
        }
    }

    /**
     * @param list<string>                                                $taxonSlugs
     * @param list<array{slug: string, updatedAt: ?\DateTimeInterface}> $products
     *
     * @return array{int, ?string}
     */
    private function sitemap(
        array $taxonSlugs = [],
        array $products = [],
        ?CatalogUrlSourceInterface $catalog = null,
        ?ChannelInterface $channel = null,
    ): array {
        if (null === $catalog) {
            $catalog = $this->createMock(CatalogUrlSourceInterface::class);
            $catalog->method('taxonSlugs')->willReturn($taxonSlugs);
            $catalog->method('products')->willReturn($products);
        }

        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel ?? $this->createMock(ChannelInterface::class));

        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn('en_US');

        $controller = new SitemapController($catalog, $channelContext, $localeContext);
        $this->wire($controller, Request::create('/sitemap.xml'));
        $response = $controller->index();

        return [$response->getStatusCode(), $response->headers->get('Content-Type')];
    }

    /** @return list<array{name: string, parameters: array<string, string>, changefreq: string, priority: string, lastmod: ?string}> */
    private function routes(): array
    {
        $routes = $this->rendered[0][1]['routes'];
        self::assertIsArray($routes);

        /** @var list<array{name: string, parameters: array<string, string>, changefreq: string, priority: string, lastmod: ?string}> $routes */
        return $routes;
    }

    /** @return list<string> */
    private static function declaredShopRouteNames(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 4).'/src/Controller/Shop/*.php') ?: [] as $file) {
            $class = 'App\\Controller\\Shop\\'.basename($file, '.php');
            self::assertTrue(class_exists($class));
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $name = $attribute->newInstance()->name;
                    if (null !== $name) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }
}
