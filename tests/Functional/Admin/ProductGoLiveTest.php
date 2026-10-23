<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\ProductGoLiveController;
use App\Entity\Product\Product;
use App\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;

final class ProductGoLiveTest extends FunctionalTestCase
{
    public function testTheProductListHasGoLiveAndTakeOfflineActions(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/admin/products/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-bulk-action="go-live"]', 'Go live');
        self::assertSelectorTextContains('[data-test-bulk-action="take-offline"]', 'Take offline');
        self::assertSelectorExists('form[action="/admin/products/bulk/go-live"][data-bulk-delete="index"] input[name="_token"]');
    }

    public function testGoLivePublishesReadyDraftsAndExplainsTheRest(): void
    {
        $ready = $this->draft('GL-READY', 'Ready drill', 'ready-drill');
        $unpriced = $this->draft('GL-NOPRICE', 'Unpriced drill', 'unpriced-drill');
        $this->removePrices($unpriced);
        $this->loginAsAdmin();
        $token = $this->tokenFromProductList();

        $this->client->request('POST', '/admin/products/bulk/go-live', [
            '_token' => $token,
            'ids' => [$ready->getId(), $unpriced->getId()],
        ], server: ['HTTP_REFERER' => 'http://localhost/admin/products/?criteria[enabled]=false&page=2']);

        self::assertResponseRedirects('http://localhost/admin/products/?criteria[enabled]=false&page=2', message: 'Back to the same filtered page.');
        self::assertTrue($this->reload($ready)->isEnabled());
        self::assertFalse($this->reload($unpriced)->isEnabled());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '1 product is now live in the shop.');
        self::assertSelectorTextContains('body', 'GL-NOPRICE (no price in channel "default")');

        $this->client->request('GET', '/en_US/products/ready-drill');
        self::assertResponseIsSuccessful();
    }

    public function testTakeOfflineHidesProductsFromTheShop(): void
    {
        $live = $this->ensureProduct('GL-LIVE', 'Live drill', 'live-drill', 'mro_materials');
        $this->loginAsAdmin();
        $token = $this->tokenFromProductList();

        $this->client->request('POST', '/admin/products/bulk/take-offline', ['_token' => $token, 'ids' => [$live->getId()]]);

        self::assertResponseRedirects('/admin/products/');
        self::assertFalse($this->reload($live)->isEnabled());
        $this->client->request('GET', '/en_US/products/live-drill');
        self::assertResponseStatusCodeSame(404);
    }

    public function testItRequiresTheCsrfToken(): void
    {
        $draft = $this->draft('GL-CSRF', 'Csrf drill', 'csrf-drill');
        $this->loginAsAdmin();

        $this->client->request('POST', '/admin/products/bulk/go-live', ['_token' => 'forged', 'ids' => [$draft->getId()]]);

        self::assertResponseRedirects('/admin/products/');
        self::assertFalse($this->reload($draft)->isEnabled());
    }

    public function testItRequiresAnAdminLogin(): void
    {
        $draft = $this->draft('GL-ANON', 'Anon drill', 'anon-drill');

        $this->client->request('POST', '/admin/products/bulk/go-live', ['ids' => [$draft->getId()]]);

        self::assertResponseRedirects('http://localhost/admin/login');
        self::assertFalse($this->reload($draft)->isEnabled());
    }

    public function testNothingSelectedIsExplained(): void
    {
        $this->loginAsAdmin();
        $token = $this->tokenFromProductList();

        $this->client->request('POST', '/admin/products/bulk/go-live', ['_token' => $token]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Select at least one product first.');
    }

    private function draft(string $code, string $name, string $slug): ProductInterface
    {
        $product = $this->ensureProduct($code, $name, $slug, 'mro_materials', enabled: false);
        if (null === $this->firstPricing($product)) {
            // ensureProduct() reuses an existing product; restore its price.
            $this->entityManager()->remove($product);
            $this->entityManager()->flush();
            $product = $this->ensureProduct($code, $name, $slug, 'mro_materials', enabled: false);
        }

        return $product;
    }

    private function removePrices(ProductInterface $product): void
    {
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        foreach ($variant->getChannelPricings()->toArray() as $pricing) {
            $variant->removeChannelPricing($pricing);
        }
        $this->entityManager()->flush();
    }

    private function firstPricing(ProductInterface $product): mixed
    {
        $variant = $product->getVariants()->first();

        return $variant instanceof ProductVariantInterface ? $variant->getChannelPricings()->first() ?: null : null;
    }

    private function reload(ProductInterface $product): ProductInterface
    {
        $this->entityManager()->clear();
        $fresh = $this->entityManager()->getRepository(Product::class)->find($product->getId());
        self::assertInstanceOf(ProductInterface::class, $fresh);

        return $fresh;
    }

    private function tokenFromProductList(): string
    {
        $crawler = $this->client->request('GET', '/admin/products/');
        $token = $crawler->filter('form[action="/admin/products/bulk/go-live"] input[name="_token"]')->attr('value');
        self::assertNotEmpty($token, 'The token comes from the real page, as a browser would send it.');
        self::assertNotSame(ProductGoLiveController::CSRF_TOKEN_ID, $token);

        return (string) $token;
    }

    private function loginAsAdmin(): void
    {
        $repository = static::getContainer()->get('sylius.repository.admin_user');
        self::assertInstanceOf(UserRepositoryInterface::class, $repository);
        $admin = $repository->findOneBy(['username' => 'admin']);
        self::assertInstanceOf(AdminUserInterface::class, $admin, 'Load the "e2e" fixture suite first.');

        $this->client->loginUser($admin, 'admin');
    }
}
