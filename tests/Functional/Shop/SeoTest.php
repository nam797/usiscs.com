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

    public function testACategoryPageHasItsOwnTitleDescriptionAndBreadcrumbData(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $crawler = $this->client->request('GET', '/safety-ppe');

        self::assertSelectorTextSame('title', 'Safety & PPE | US Industrial Supply Chain Solutions');
        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertMatchesRegularExpression('/^Safety & PPE: [\d,]+ products?/', $description);
        self::assertStringNotContainsString('&amp;', $description, 'escaped once, not twice');
        self::assertSame('Safety & PPE | US Industrial Supply Chain Solutions', $crawler->filter('meta[property="og:title"]')->attr('content'));

        $breadcrumbs = $this->jsonLd($crawler, 'BreadcrumbList');
        self::assertSame(['Home', 'Products', 'Safety & PPE'], array_column(self::arr($breadcrumbs['itemListElement']), 'name'));

        $this->client->request('GET', '/safety-ppe?page=2');
        self::assertSelectorTextSame('title', 'Safety & PPE – Page 2 | US Industrial Supply Chain Solutions');

        $this->client->request('GET', '/products');
        self::assertSelectorTextSame('title', 'All products | US Industrial Supply Chain Solutions');
    }

    public function testAProductPageHasProductAndBreadcrumbData(): void
    {
        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');

        $crawler = $this->client->request('GET', '/products/safety-glasses');

        $product = $this->jsonLd($crawler, 'Product');
        self::assertSame('Safety Glasses', $product['name']);
        self::assertSame('http://localhost/products/safety-glasses', $product['url']);
        $offer = self::arr($product['offers']);
        self::assertSame('Offer', $offer['@type']);
        self::assertSame('USD', $offer['priceCurrency']);
        self::assertIsString($offer['price']);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $offer['price']);
        self::assertArrayNotHasKey('aggregateRating', $product);
        self::assertSame(['Home', 'Products', 'Safety & PPE', 'Safety Glasses'], array_column(self::arr($this->jsonLd($crawler, 'BreadcrumbList')['itemListElement']), 'name'));
    }

    public function testTheHomepageSaysWhoRunsTheSite(): void
    {
        $crawler = $this->client->request('GET', '/');

        $organization = $this->jsonLd($crawler, 'Organization');
        self::assertSame('US Industrial Supply Chain Solutions', $organization['name']);
        self::assertSame('+1-912-665-7257', $organization['telephone']);
        self::assertIsString($organization['logo']);
        self::assertStringContainsString('/build/app/shop/images/logo', $organization['logo']);
        self::assertSame('WebSite', $this->jsonLd($crawler, 'WebSite')['@type']);
    }

    /** @return array<string, mixed> the page's JSON-LD block of this @type */
    private function jsonLd(\Symfony\Component\DomCrawler\Crawler $crawler, string $type): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            if (is_array($data) && $type === ($data['@type'] ?? null)) {
                return $data;
            }
        }
        self::fail(sprintf('No %s structured data on the page.', $type));
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
