<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Command\SetupCategoriesCommand;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class CatalogTest extends FunctionalTestCase
{
    public function testTheShopMenuListsTheSixCategoriesInOrder(): void
    {
        $crawler = $this->client->request('GET', '/');

        $slugger = new AsciiSlugger();
        $categoryHrefs = array_map(static fn (string $name): string => '/'.$slugger->slug($name)->lower(), SetupCategoriesCommand::CATEGORIES);
        $menu = $crawler->filter('nav a')->each(static fn ($a): ?string => in_array($a->attr('href'), $categoryHrefs, true) ? trim($a->text()) : null);

        self::assertSame(array_values(SetupCategoriesCommand::CATEGORIES), array_values(array_filter($menu)));
    }

    public function testACategoryPageListsItsProducts(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $this->client->request('GET', '/safety-ppe');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Safety & PPE');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('body', 'Safety Glasses');
    }

    public function testACategoryPageShows24ProductsAndOffersMore(): void
    {
        $this->client->request('GET', '/safety-ppe');

        self::assertSelectorExists('a[href="/safety-ppe?limit=48"]');
        self::assertSelectorExists('a[href="/safety-ppe?limit=96"]');
    }

    public function testAnEmptyCategoryPageStillLoads(): void
    {
        $this->client->request('GET', '/specialty-hard-to-find');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Specialty & Hard-to-Find');
    }

    public function testAProductPageShowsTheProductAndItsPrice(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $this->client->request('GET', '/products/safety-glasses');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Safety Glasses');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('body', '$12.99');
    }

    public function testADisabledProductIsNotFound(): void
    {
        $this->ensureProduct('E2E_HIDDEN_HARD_HAT', 'Discontinued Hard Hat', 'discontinued-hard-hat', 'safety_ppe', enabled: false);

        $this->client->request('GET', '/products/discontinued-hard-hat');

        self::assertResponseStatusCodeSame(404);
    }
}
