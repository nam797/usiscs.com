<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Tests\Functional\FunctionalTestCase;

final class SitemapTest extends FunctionalTestCase
{
    private const CONTENT_PAGES = [
        'http://localhost/',
        'http://localhost/services',
        'http://localhost/about',
        'http://localhost/contact',
        'http://localhost/quote',
        'http://localhost/terms',
        'http://localhost/privacy',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureProduct('E2E_SAFETY_GLASSES', 'Safety Glasses', 'safety-glasses', 'safety_ppe');
        $this->ensureProduct('E2E_HIDDEN_HARD_HAT', 'Discontinued Hard Hat', 'discontinued-hard-hat', 'safety_ppe', enabled: false);
    }

    public function testItIsValidXmlWithTheContentPagesFirst(): void
    {
        $urls = array_column($this->entries(), 'loc');

        self::assertSame(self::CONTENT_PAGES, array_slice($urls, 0, 7));
        self::assertResponseHeaderSame('Content-Type', 'application/xml');
    }

    public function testItListsEnabledProductsWithTheirLastModifiedDate(): void
    {
        $entries = array_column($this->entries(), null, 'loc');

        self::assertArrayHasKey('http://localhost/products/safety-glasses', $entries);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entries['http://localhost/products/safety-glasses']['lastmod']);
    }

    public function testItLeavesOutDisabledProducts(): void
    {
        self::assertNotContains('http://localhost/products/discontinued-hard-hat', array_column($this->entries(), 'loc'));
    }

    public function testItListsOnlyCategoriesThatHaveProducts(): void
    {
        $categoryUrls = array_values(array_filter(
            array_column($this->entries(), 'loc'),
            static fn (string $url): bool => !in_array($url, self::CONTENT_PAGES, true) && !str_contains($url, '/products/'),
        ));

        self::assertContains('http://localhost/safety-ppe', $categoryUrls);
        // Empty categories (and the "Products" root itself) are thin pages.
        self::assertNotContains('http://localhost/specialty-hard-to-find', $categoryUrls);
        self::assertNotContains('http://localhost/products', $categoryUrls);
    }

    public function testEveryListedUrlLoads(): void
    {
        foreach (array_column($this->entries(), 'loc') as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('%s is in the sitemap but does not load.', $url));
        }
    }

    public function testRobotsTxtExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/public/robots.txt');
    }

    /** @return list<array{loc: string, lastmod: string}> */
    private function entries(): array
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();

        $xml = simplexml_load_string((string) $this->client->getResponse()->getContent());
        self::assertNotFalse($xml, 'sitemap.xml is not well-formed XML.');
        self::assertSame('urlset', $xml->getName());

        $entries = [];
        foreach ($xml->url as $url) {
            $entries[] = ['loc' => (string) $url->loc, 'lastmod' => (string) $url->lastmod];
        }

        return $entries;
    }
}
