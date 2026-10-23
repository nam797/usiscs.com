<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Command\SetupCategoriesCommand;
use App\Tests\Functional\FunctionalTestCase;

final class CatalogTest extends FunctionalTestCase
{
    public function testTheShopMenuListsTheSixCategoriesInOrder(): void
    {
        $crawler = $this->client->request('GET', '/en_US/');

        $menu = $crawler->filter('nav a[href*="/taxons/"]')->each(static fn ($a): string => trim($a->text()));

        self::assertSame(array_values(SetupCategoriesCommand::CATEGORIES), $menu);
    }

    public function testACategoryPageListsItsProducts(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $this->client->request('GET', '/en_US/taxons/products/safety-ppe');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Safety & PPE');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('body', 'Safety Glasses');
    }

    public function testAnEmptyCategoryPageStillLoads(): void
    {
        $this->client->request('GET', '/en_US/taxons/products/specialty-hard-to-find');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Specialty & Hard-to-Find');
    }

    public function testAProductPageShowsTheProductAndItsPrice(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $this->client->request('GET', '/en_US/products/safety-glasses');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Safety Glasses');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('body', '$12.99');
    }

    public function testADisabledProductIsNotFound(): void
    {
        $this->ensureProduct('E2E_HIDDEN_HARD_HAT', 'Discontinued Hard Hat', 'discontinued-hard-hat', 'safety_ppe', enabled: false);

        $this->client->request('GET', '/en_US/products/discontinued-hard-hat');

        self::assertResponseStatusCodeSame(404);
    }
}
