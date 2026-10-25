<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PaymentScriptsTest extends FunctionalTestCase
{
    public function testBrowsingPagesDoNotLoadThePaymentScripts(): void
    {
        foreach (['/', '/safety-ppe', '/cart/', '/contact'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('script[src*="js.mollie.com"]', $path);
            self::assertSelectorNotExists('script[src*="datacollection"]', $path);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function routes(): iterable
    {
        yield 'checkout address' => ['sylius_shop_checkout_address', true];
        yield 'checkout payment' => ['sylius_shop_checkout_select_payment', true];
        yield 'checkout summary' => ['sylius_shop_checkout_complete', true];
        yield 'pay for an order' => ['sylius_shop_order_pay', true];
        yield 'order page (pay again)' => ['sylius_shop_order_show', true];
        yield 'product page' => ['sylius_shop_product_show', false];
        yield 'cart' => ['sylius_shop_cart_summary', false];
    }

    // Checkout pages need a cart, so the template is rendered for each route directly.
    #[DataProvider('routes')]
    public function testThePaymentScriptsLoadOnlyWhereAPaymentIsTaken(string $route, bool $loaded): void
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);
        $requestStack = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($request);
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('shop/payment_scripts.html.twig');

        self::assertSame($loaded, str_contains($html, 'https://js.mollie.com/v1/mollie.js'));
        self::assertSame($loaded, str_contains($html, '/datacollection/datacollection.js'));
    }
}
