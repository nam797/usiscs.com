<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Catalog\Category\CategoryTree;
use App\Tests\Functional\FunctionalTestCase;

final class CatalogTest extends FunctionalTestCase
{
    public function testTheShopMenuListsTheSevenDepartmentsInOrderEachWithItsCategories(): void
    {
        $crawler = $this->client->request('GET', '/');

        $departments = $crawler->filter('#navbarNav [data-bs-toggle="dropdown"]')->each(static fn ($a): string => trim($a->text()));
        self::assertSame(array_map(static fn (array $node): string => $node[0], array_values(CategoryTree::TREE)), $departments);

        $tools = $crawler->filter('#navbarNav .dropdown-menu a')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertContains('/tools', $tools, 'Each dropdown starts with a link to the department itself.');
        self::assertContains('/tools/hand-tools', $tools);
        self::assertContains('/safety-ppe/hand-protection', $tools);
    }

    public function testTheHomepageHasATilePerDepartmentAndOneForAnythingElse(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'eye_face_protection');

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(count(CategoryTree::TREE) + 1, '#categories .category-tile');
        self::assertSelectorTextContains('#categories a.category-tile[href="/safety-ppe"]', 'products');
        self::assertSelectorTextContains('#categories .col-6:last-child a.category-tile--ask[href="/quote"]', 'Specialty & Hard-to-Find');
    }

    public function testAThirdLevelCategoryPageListsItsProductsAndTheDepartmentShowsThemToo(): void
    {
        $this->ensureProduct('E2E_CUT_GLOVES', 'Cut Gloves', 'cut-gloves', 'cut_resistant_gloves');

        $this->client->request('GET', '/safety-ppe/hand-protection/cut-resistant-gloves');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cut-Resistant Gloves');
        self::assertSelectorTextContains('body', 'Cut Gloves');

        $this->client->request('GET', '/safety-ppe');
        self::assertSelectorTextContains('body', 'Cut Gloves');
    }

    public function testTheOldCategoryPagesRedirectToTheirReplacements(): void
    {
        foreach (CategoryTree::RETIRED as [$oldSlug, $newPath]) {
            $this->client->request('GET', '/'.$oldSlug.'?page=2');
            self::assertResponseRedirects($newPath.'?page=2', 301);
        }
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

    public function testTheCategorySearchBoxSaysWhatItSearches(): void
    {
        $this->client->request('GET', '/safety-ppe');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="criteria[search][value]"][placeholder="Search this category"][aria-label="Search this category"]');
    }

    public function testTheBreadcrumbsLinkToAllProducts(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        // Category page: Home / Products / Safety & PPE.
        $this->client->request('GET', '/safety-ppe');
        self::assertSelectorExists('.breadcrumb a[href="/products"]');
        self::assertSelectorTextContains('.breadcrumb a[href="/products"]', 'Products');

        // Product page: Home / Products / Safety & PPE / Safety Glasses.
        $this->client->request('GET', '/products/safety-glasses');
        self::assertSelectorExists('.breadcrumb a[href="/products"]');
        self::assertSelectorExists('.breadcrumb a[href="/safety-ppe"]');

        // And /products is a real page listing the catalog.
        $this->client->request('GET', '/products');
        self::assertResponseIsSuccessful();
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
        $this->client->request('GET', '/abrasives/grinding-cutting-finishing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Grinding, Cutting & Finishing');
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
