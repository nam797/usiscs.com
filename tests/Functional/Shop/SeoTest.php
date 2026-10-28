<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Tests\Functional\FunctionalTestCase;

/**
 * What search engines see: one canonical address per page, private pages
 * kept out of the index, and product pages with their own description.
 */
final class SeoTest extends FunctionalTestCase
{
    private const SITE_DESCRIPTION = 'US Industrial Supply Chain Solutions protects customer uptime';

    public function testAReSortedOrResizedCategoryPointsAtThePlainPageAndIsNotIndexed(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $this->client->request('GET', '/safety-ppe?limit=48&sorting[name]=asc');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/safety-ppe"]');
        self::assertSelectorExists('meta[property="og:url"][content="http://localhost/safety-ppe"]');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');

        $this->client->request('GET', '/safety-ppe?page=2&limit=24');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/safety-ppe?page=2"]');

        $this->client->request('GET', '/safety-ppe');
        self::assertResponseNotHasHeader('X-Robots-Tag');
    }

    public function testPrivatePagesAreNotIndexed(): void
    {
        foreach (['/cart/', '/login', '/register'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow', $path);
        }

        $robots = (string) file_get_contents(dirname(__DIR__, 3).'/public/robots.txt');
        foreach (['/cart', '/checkout', '/account', '/login', '/*?*sorting', '/*?*limit='] as $path) {
            self::assertStringContainsString("Disallow: {$path}\n", $robots);
        }
    }

    public function testAProductPageHasItsOwnDescriptionAndProductTagsAndNoEmptyReviews(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $crawler = $this->client->request('GET', '/products/safety-glasses');

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('X-Robots-Tag');
        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringStartsWith('Safety Glasses.', $description);
        self::assertStringNotContainsString(self::SITE_DESCRIPTION, $description);
        self::assertSelectorExists('meta[property="og:type"][content="product"]');
        self::assertSelectorExists('meta[property="product:price:currency"][content="USD"]');
        self::assertSelectorTextNotContains('body', 'Add your review');
        self::assertSelectorTextNotContains('body', 'No description');
    }
}
