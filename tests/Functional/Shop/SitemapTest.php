<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Tests\Functional\FunctionalTestCase;

final class SitemapTest extends FunctionalTestCase
{
    public function testItIsValidXmlListingEveryPublicPage(): void
    {
        self::assertSame(
            [
                'http://localhost/en_US/',
                'http://localhost/services',
                'http://localhost/about',
                'http://localhost/contact',
                'http://localhost/quote',
                'http://localhost/terms',
                'http://localhost/privacy',
            ],
            $this->sitemapUrls(),
        );
        self::assertResponseHeaderSame('Content-Type', 'application/xml');
    }

    public function testEveryListedUrlLoads(): void
    {
        foreach ($this->sitemapUrls() as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('%s is in the sitemap but does not load.', $url));
        }
    }

    public function testRobotsTxtExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/public/robots.txt');
    }

    /** @return list<string> */
    private function sitemapUrls(): array
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();

        $xml = simplexml_load_string((string) $this->client->getResponse()->getContent());
        self::assertNotFalse($xml, 'sitemap.xml is not well-formed XML.');
        self::assertSame('urlset', $xml->getName());

        $urls = [];
        foreach ($xml->url as $entry) {
            $urls[] = (string) $entry->loc;
        }

        return $urls;
    }
}
