<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\RobotsTagListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RobotsTagListenerTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, int, ?string}> */
    public static function pages(): iterable
    {
        // route, query, status, expected X-Robots-Tag
        yield 'home' => ['sylius_shop_homepage', [], 200, null];
        yield 'category' => ['sylius_shop_product_index', [], 200, null];
        yield 'category, page 2' => ['sylius_shop_product_index', ['page' => '2'], 200, null];
        yield 'category, 48 per page' => ['sylius_shop_product_index', ['limit' => '48'], 200, 'noindex, follow'];
        yield 'category, sorted' => ['sylius_shop_product_index', ['sorting' => ['price' => 'asc']], 200, 'noindex, follow'];
        yield 'category, searched' => ['sylius_shop_product_index', ['criteria' => ['search' => ['value' => 'gloves']]], 200, 'noindex, follow'];
        yield 'product' => ['sylius_shop_product_show', [], 200, null];
        yield 'about' => ['app_shop_about', [], 200, null];
        yield 'quote form' => ['app_shop_rfq', [], 200, null];
        yield 'unknown category (404)' => ['sylius_shop_product_index', [], 404, 'noindex'];
        yield 'cart' => ['sylius_shop_cart_summary', [], 200, 'noindex, nofollow'];
        yield 'checkout' => ['sylius_shop_checkout_address', [], 200, 'noindex, nofollow'];
        yield 'login' => ['sylius_shop_login', [], 200, 'noindex, nofollow'];
        yield 'register' => ['sylius_shop_register', [], 200, 'noindex, nofollow'];
        yield 'account' => ['sylius_shop_account_dashboard', [], 200, 'noindex, nofollow'];
        yield 'write a review' => ['sylius_shop_product_review_create', [], 200, 'noindex, nofollow'];
        yield 'a route added later' => ['some_new_route', [], 200, 'noindex, nofollow'];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('pages')]
    public function testOnlyPagesWorthFindingAreIndexable(string $route, array $query, int $status, ?string $expected): void
    {
        self::assertSame($expected, $this->dispatch($route, $query, new Response('<html></html>', $status))->headers->get('X-Robots-Tag'));
    }

    public function testItLeavesNonHtmlAndAlreadyTaggedResponsesAlone(): void
    {
        $xml = new Response('<urlset/>', 200, ['Content-Type' => 'application/xml']);
        self::assertNull($this->dispatch('app_shop_sitemap', [], $xml)->headers->get('X-Robots-Tag'));

        $tagged = new Response('', 200, ['X-Robots-Tag' => 'noindex']);
        self::assertSame('noindex', $this->dispatch('sylius_shop_cart_summary', [], $tagged)->headers->get('X-Robots-Tag'));
    }

    public function testItIgnoresSubRequests(): void
    {
        self::assertNull($this->dispatch('sylius_shop_cart_summary', [], new Response(), HttpKernelInterface::SUB_REQUEST)->headers->get('X-Robots-Tag'));
    }

    /** @param array<string, mixed> $query */
    private function dispatch(string $route, array $query, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $request = new Request($query);
        $request->attributes->set('_route', $route);
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response);
        (new RobotsTagListener())->onKernelResponse($event);

        return $response;
    }
}
