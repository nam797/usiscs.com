<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Shop;

use App\Controller\Shop\SitemapController;
use App\Tests\Unit\Controller\ControllerTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapControllerTest extends ControllerTestCase
{
    public function testItIsServedAsXml(): void
    {
        $response = $this->sitemap();

        self::assertSame(200, $response[0]);
        self::assertSame('application/xml', $response[1]);
        self::assertSame(['shop/sitemap/index.xml.twig'], $this->renderedTemplates());
    }

    public function testItListsEveryPublicPage(): void
    {
        $this->sitemap();

        self::assertSame(
            [
                'sylius_shop_homepage',
                'app_shop_services',
                'app_shop_about',
                'app_shop_contact',
                'app_shop_rfq',
                'app_shop_terms',
                'app_shop_privacy',
            ],
            array_column($this->routes(), 'name'),
        );
    }

    public function testEveryListedAppRouteActuallyExists(): void
    {
        // Guards against a page's route being renamed without updating the
        // sitemap, which would 500 the whole sitemap.xml on url().
        $this->sitemap();

        $declared = self::declaredShopRouteNames();
        foreach (array_column($this->routes(), 'name') as $name) {
            if ('sylius_shop_homepage' === $name) {
                continue;
            }
            self::assertContains($name, $declared);
        }
    }

    public function testEveryEntryHasAValidChangefreqAndPriority(): void
    {
        $this->sitemap();

        foreach ($this->routes() as $route) {
            self::assertContains($route['changefreq'], ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']);
            self::assertMatchesRegularExpression('/^(0\.\d|1\.0)$/', $route['priority']);
        }
    }

    /** @return array{int, ?string} */
    private function sitemap(): array
    {
        $controller = new SitemapController();
        $this->wire($controller, Request::create('/sitemap.xml'));
        $response = $controller->index();

        return [$response->getStatusCode(), $response->headers->get('Content-Type')];
    }

    /** @return list<array{name: string, changefreq: string, priority: string}> */
    private function routes(): array
    {
        $routes = $this->rendered[0][1]['routes'];
        self::assertIsArray($routes);

        /** @var list<array{name: string, changefreq: string, priority: string}> $routes */
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
