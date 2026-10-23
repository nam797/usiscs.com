<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Import;

use App\Catalog\Import\ProductImageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * URLs use public IP literals (documentation range would be "private" to
 * some checks, so a real public address is used) so no DNS lookup happens.
 */
final class ProductImageFetcherTest extends TestCase
{
    private const URL = 'https://93.184.215.14/hb500.png';

    public function testItDownloadsAnImageToATemporaryFile(): void
    {
        $file = $this->fetcher(new MockResponse('PNGDATA', ['response_headers' => ['content-type' => 'image/png']]))->fetch(self::URL);

        try {
            self::assertSame('PNGDATA', file_get_contents($file->getPathname()));
            self::assertSame('png', $file->getExtension());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function testTheContentTypeMayCarryParameters(): void
    {
        $file = $this->fetcher(new MockResponse('JPG', ['response_headers' => ['content-type' => 'image/jpeg; charset=binary']]))->fetch(self::URL);
        unlink($file->getPathname());

        self::assertSame('jpg', $file->getExtension());
    }

    /** @return iterable<string, array{string}> */
    public static function blockedAddresses(): iterable
    {
        yield 'localhost' => ['http://127.0.0.1/a.png'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'private network' => ['http://10.0.0.5/a.png'];
        yield 'ipv6 loopback' => ['http://[::1]/a.png'];
    }

    #[DataProvider('blockedAddresses')]
    public function testItRefusesToReachTheServersOwnNetwork(string $url): void
    {
        $this->expectException(\RuntimeException::class);

        $this->fetcher(new MockResponse('secret', ['response_headers' => ['content-type' => 'image/png']]))->fetch($url);
    }

    public function testNonImagesAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"text/html" is not a JPEG, PNG, WebP or GIF image');

        $this->fetcher(new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']]))->fetch(self::URL);
    }

    public function testSvgIsRefused(): void
    {
        // SVG can carry scripts; not accepted from outside sources.
        $this->expectException(\RuntimeException::class);

        $this->fetcher(new MockResponse('<svg/>', ['response_headers' => ['content-type' => 'image/svg+xml']]))->fetch(self::URL);
    }

    public function testADeclaredSizeOverTheLimitIsRefusedBeforeDownloading(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('larger than 5 MB');

        $this->fetcher(new MockResponse(str_repeat('x', ProductImageFetcher::MAX_BYTES + 1), ['response_headers' => [
            'content-type' => 'image/png',
            'content-length' => (string) (ProductImageFetcher::MAX_BYTES + 1),
        ]]))->fetch(self::URL);
    }

    public function testAnUndeclaredOversizedBodyIsCutOff(): void
    {
        $before = glob(sys_get_temp_dir().'/usiscs-image-*') ?: [];

        try {
            $this->fetcher(new MockResponse(str_repeat('x', ProductImageFetcher::MAX_BYTES + 1), ['response_headers' => ['content-type' => 'image/png']]))->fetch(self::URL);
            self::fail('Expected the download to be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('larger than 5 MB', $e->getMessage());
        }

        self::assertSame($before, glob(sys_get_temp_dir().'/usiscs-image-*') ?: [], 'The partial download is deleted.');
    }

    public function testAnErrorStatusIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        $this->fetcher(new MockResponse('', ['http_code' => 404]))->fetch(self::URL);
    }

    private function fetcher(MockResponse $response): ProductImageFetcher
    {
        return new ProductImageFetcher(new MockHttpClient($response));
    }
}
