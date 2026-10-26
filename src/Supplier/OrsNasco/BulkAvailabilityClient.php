<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * ORS Nasco "Price & Availability REST API (version 2.0)": despite the name,
 * it returns stock only -- one call lists every ORS item with its total
 * available across all warehouses. ORS refreshes it 6 times a day (00:30,
 * 07:30, 10:30, 11:30, 13:30, 15:30 Central). OAuth 2.0 client credentials.
 *
 * Prices come from the separate SOAP service, see PriceClient.
 */
final class BulkAvailabilityClient implements BulkAvailabilityClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire(env: 'ORS_AVAILABILITY_URL')]
        private readonly string $url,
        #[Autowire(env: 'ORS_OAUTH_TOKEN_URL')]
        private readonly string $tokenUrl,
        #[Autowire(env: 'ORS_OAUTH_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'ORS_OAUTH_CLIENT_SECRET')]
        private readonly string $clientSecret,
    ) {
    }

    public function fetchAll(): array
    {
        if ('' === $this->url || '' === $this->tokenUrl || '' === $this->clientId || '' === $this->clientSecret) {
            throw new OrsApiException('ORS availability API is not configured: set ORS_OAUTH_TOKEN_URL, ORS_OAUTH_CLIENT_ID and ORS_OAUTH_CLIENT_SECRET');
        }

        try {
            $response = $this->request($this->token());
            if (401 === $response->getStatusCode()) {
                // The cached token was revoked or expired early: get a new one, once.
                $this->cache->delete($this->tokenCacheKey());
                $response = $this->request($this->token());
            }

            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw new OrsApiException(sprintf('ORS availability API answered HTTP %d', $status), $status >= 500);
            }

            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new OrsApiException(sprintf('ORS availability API failed: %s', $e->getMessage()), true, $e);
        }

        return self::parse($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, AvailabilityRow>
     */
    public static function parse(array $data): array
    {
        $products = $data['products'] ?? null;
        if (!is_array($products) || [] === $products) {
            // Never treat this as "nothing in stock": the sync would zero everything.
            throw new OrsApiException('ORS availability API returned no products');
        }

        $rows = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $item = $product['orsProduct'] ?? null;
            $total = $product['totalAvailability'] ?? null;
            if (!is_string($item) || '' === trim($item) || !(is_int($total) || (is_string($total) && ctype_digit($total)))) {
                continue;
            }
            $uom = $product['uom'] ?? '';
            $item = trim($item);

            $rows[$item] = new AvailabilityRow($item, is_string($uom) ? trim($uom) : '', max(0, (int) $total));
        }

        if ([] === $rows) {
            throw new OrsApiException('ORS availability API returned no usable products');
        }

        return $rows;
    }

    private function request(string $token): ResponseInterface
    {
        return $this->httpClient->request('POST', $this->url, [
            'auth_bearer' => $token,
            'json' => ['requestType' => 'bulk'],
            'timeout' => 60,
            'max_duration' => 300,
        ]);
    }

    private function token(): string
    {
        return $this->cache->get($this->tokenCacheKey(), function (ItemInterface $item): string {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                'timeout' => 30,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new OrsApiException(sprintf('ORS OAuth token request answered HTTP %d; check ORS_OAUTH_CLIENT_ID and ORS_OAUTH_CLIENT_SECRET', $response->getStatusCode()));
            }

            $data = $response->toArray();
            $token = $data['access_token'] ?? null;
            if (!is_string($token) || '' === $token) {
                throw new OrsApiException('ORS OAuth token response had no access_token');
            }

            $lifetime = $data['expires_in'] ?? 3600;
            $item->expiresAfter(max(60, (is_numeric($lifetime) ? (int) $lifetime : 3600) - 60));

            return $token;
        });
    }

    private function tokenCacheKey(): string
    {
        return 'ors_nasco_token_'.hash('xxh128', $this->tokenUrl.'|'.$this->clientId);
    }
}
