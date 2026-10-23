<?php

declare(strict_types=1);

namespace App\Catalog\Import;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a product image from a URL in the import sheet/feed.
 *
 * The URLs come from outside, so this refuses anything that isn't a
 * reasonably sized JPEG/PNG/WebP/GIF, and can't be pointed at the server's
 * own network (NoPrivateNetworkHttpClient blocks localhost, private and
 * cloud-metadata addresses, including via redirects).
 */
final class ProductImageFetcher
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private readonly HttpClientInterface $client;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->client = new NoPrivateNetworkHttpClient($httpClient);
    }

    /**
     * @return File a temporary file; the caller deletes it when done
     *
     * @throws \RuntimeException with a reason fit for the import report
     */
    public function fetch(string $url): File
    {
        $path = null;

        try {
            $response = $this->client->request('GET', $url, ['timeout' => 15, 'max_redirects' => 3]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException(sprintf('the server answered HTTP %d', $response->getStatusCode()));
            }

            $headers = $response->getHeaders();
            $type = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
            if (!isset(self::TYPES[$type])) {
                throw new \RuntimeException(sprintf('"%s" is not a JPEG, PNG, WebP or GIF image', '' === $type ? 'unknown type' : $type));
            }
            if ((int) ($headers['content-length'][0] ?? 0) > self::MAX_BYTES) {
                throw new \RuntimeException('the image is larger than 5 MB');
            }

            $path = sprintf('%s/usiscs-image-%s.%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), self::TYPES[$type]);
            $handle = fopen($path, 'w');
            if (false === $handle) {
                throw new \RuntimeException('could not create a temporary file');
            }

            $size = 0;
            try {
                foreach ($this->client->stream($response) as $chunk) {
                    $size += strlen($chunk->getContent());
                    if ($size > self::MAX_BYTES) {
                        throw new \RuntimeException('the image is larger than 5 MB');
                    }
                    fwrite($handle, $chunk->getContent());
                }
            } finally {
                fclose($handle);
            }

            return new File($path);
        } catch (\RuntimeException|ExceptionInterface $e) {
            if (null !== $path && is_file($path)) {
                unlink($path);
            }

            throw $e instanceof \RuntimeException && !$e instanceof ExceptionInterface
                ? $e
                : new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
