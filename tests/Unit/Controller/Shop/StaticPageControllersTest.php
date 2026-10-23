<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Shop;

use App\Controller\Shop\AboutController;
use App\Controller\Shop\PrivacyController;
use App\Controller\Shop\ServicesController;
use App\Controller\Shop\TermsController;
use App\Tests\Unit\Controller\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StaticPageControllersTest extends ControllerTestCase
{
    /** @return iterable<string, array{class-string<AbstractController>, string, string, string}> */
    public static function pages(): iterable
    {
        yield 'about' => [AboutController::class, '/about', 'app_shop_about', 'shop/about/index.html.twig'];
        yield 'services' => [ServicesController::class, '/services', 'app_shop_services', 'shop/services/index.html.twig'];
        yield 'privacy' => [PrivacyController::class, '/privacy', 'app_shop_privacy', 'shop/privacy/index.html.twig'];
        yield 'terms' => [TermsController::class, '/terms', 'app_shop_terms', 'shop/terms/index.html.twig'];
    }

    /** @param class-string<AbstractController> $class */
    #[DataProvider('pages')]
    public function testItRendersThePageTemplate(string $class, string $path, string $routeName, string $template): void
    {
        $controller = new $class();
        $this->wire($controller, Request::create($path));

        $index = [$controller, 'index'];
        self::assertIsCallable($index);
        $response = $index();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$template], $this->renderedTemplates());
    }

    /** @param class-string<AbstractController> $class */
    #[DataProvider('pages')]
    public function testItIsRoutedAtItsPublicPath(string $class, string $path, string $routeName, string $template): void
    {
        $route = (new \ReflectionMethod($class, 'index'))->getAttributes(Route::class)[0]->newInstance();

        self::assertSame($path, $route->path);
        self::assertSame($routeName, $route->name);
    }

    /** @param class-string<AbstractController> $class */
    #[DataProvider('pages')]
    public function testItsTemplateExists(string $class, string $path, string $routeName, string $template): void
    {
        self::assertFileExists(dirname(__DIR__, 4).'/templates/'.$template);
    }
}
