<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ShopPagesTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pages(): iterable
    {
        yield 'home' => ['/en_US/', 'Your Supply Chain Problem Is Our Job.'];
        yield 'services' => ['/services', 'Every Service Built Around Your Uptime.'];
        yield 'about' => ['/about', 'Built to Keep Operations Running.'];
        yield 'contact' => ['/contact', 'Get In Touch'];
        yield 'quote' => ['/quote', 'Request a Quote'];
        yield 'privacy' => ['/privacy', 'Privacy Policy'];
        yield 'terms' => ['/terms', 'Terms of Service'];
    }

    #[DataProvider('pages')]
    public function testThePageRendersWithItsHeading(string $path, string $heading): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $heading);
        // One H1 per page: a stray second one (e.g. a menu title) muddles SEO.
        self::assertSelectorCount(1, 'h1');
    }

    #[DataProvider('pages')]
    public function testThePageHasTheSharedLayoutAndSeoTags(string $path, string $heading): void
    {
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();

        // Brand header and favicon set.
        self::assertSelectorExists('img[alt^="US Industrial Supply"]');
        self::assertSelectorExists('link[rel="icon"][href="/favicon.ico"]');
        self::assertSelectorExists('link[rel="manifest"][href="/site.webmanifest"]');

        // Built CSS from both the core and the brand entry.
        self::assertSelectorExists('link[rel="stylesheet"][href*="/build/shop/"]');
        self::assertSelectorExists('link[rel="stylesheet"][href*="/build/app/shop/"]');

        // SEO baseline: every page has a description, canonical and OG tags.
        self::assertNotSame('', trim((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertSame('http://localhost'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSelectorExists('meta[property="og:title"]');
    }

    #[DataProvider('pages')]
    public function testThePageCarriesTheSecurityHeaders(string $path, string $heading): void
    {
        $this->client->request('GET', $path);

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::assertResponseHeaderSame('X-Frame-Options', 'sameorigin');
        // Tests run with APP_DEBUG=0 (phpunit.xml.dist), like production.
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=15768000; includeSubDomains');
    }

    public function testTheRootRedirectsToTheLocalisedHomepage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/en_US/');
    }

    public function testTheNavigationLinksToEveryMainPage(): void
    {
        $crawler = $this->client->request('GET', '/en_US/');

        $hrefs = $crawler->filter('nav a')->each(static fn ($a): string => (string) $a->attr('href'));

        foreach (['/services', '/about', '/contact', '/quote'] as $path) {
            self::assertContains($path, $hrefs, sprintf('Navigation has no link to %s.', $path));
        }
    }

    public function testTheFooterLinksToTheLegalPages(): void
    {
        $crawler = $this->client->request('GET', '/en_US/');

        $hrefs = $crawler->filter('footer a')->each(static fn ($a): string => (string) $a->attr('href'));

        self::assertContains('/terms', $hrefs);
        self::assertContains('/privacy', $hrefs);
    }

    public function testAnUnknownPageIsA404(): void
    {
        $this->client->request('GET', '/this-page-does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheBrandAssetsAreServedFromPublic(): void
    {
        foreach (['favicon.ico', 'favicon-32x32.png', 'apple-touch-icon.png', 'site.webmanifest', 'robots.txt'] as $file) {
            self::assertFileExists(dirname(__DIR__, 3).'/public/'.$file);
        }
    }
}
