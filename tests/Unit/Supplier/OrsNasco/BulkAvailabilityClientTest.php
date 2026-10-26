<?php

declare(strict_types=1);

namespace App\Tests\Unit\Supplier\OrsNasco;

use App\Supplier\OrsNasco\BulkAvailabilityClient;
use App\Supplier\OrsNasco\OrsApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BulkAvailabilityClientTest extends TestCase
{
    private const URL = 'https://apim.workato.test/orsnascotest/availability/bulkavailability';
    private const TOKEN_URL = 'https://auth.orsnasco.test/oauth/token';

    /** @var list<array{string, string, array<mixed>}> */
    private array $requests = [];

    /** @var list<MockResponse> what the availability endpoint answers, in order */
    private array $answers = [];

    private int $tokensIssued = 0;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->answers = [];
        $this->tokensIssued = 0;
    }

    public function testItReturnsStockForEveryItemKeyedByItemNumber(): void
    {
        $this->answers = [new MockResponse(self::fixture('bulk_availability.json'))];

        $rows = $this->client()->fetchAll();

        self::assertSame(['312-7', '680-11-921A', '037-1001'], array_keys($rows));
        self::assertSame(12534, $rows['312-7']->totalAvailable);
        self::assertSame('PK', $rows['680-11-921A']->unitOfMeasure);
        self::assertSame(0, $rows['037-1001']->totalAvailable);
    }

    public function testItAsksForTheBulkListWithABearerToken(): void
    {
        $this->answers = [new MockResponse(self::fixture('bulk_availability.json'))];

        $this->client()->fetchAll();

        [$tokenRequest, $bulkRequest] = $this->requests;
        self::assertSame(['POST', self::TOKEN_URL], [$tokenRequest[0], $tokenRequest[1]]);
        self::assertStringContainsString('grant_type=client_credentials', self::body($tokenRequest[2]));
        self::assertSame(['POST', self::URL], [$bulkRequest[0], $bulkRequest[1]]);
        self::assertSame('{"requestType":"bulk"}', self::body($bulkRequest[2]));
        self::assertContains('Authorization: Bearer token-1', self::headers($bulkRequest[2]));
    }

    public function testTheTokenIsReusedBetweenRuns(): void
    {
        $this->answers = [new MockResponse(self::fixture('bulk_availability.json')), new MockResponse(self::fixture('bulk_availability.json'))];
        $client = $this->client();

        $client->fetchAll();
        $client->fetchAll();

        self::assertSame(1, $this->tokensIssued);
    }

    public function testARejectedTokenIsReplacedOnce(): void
    {
        $this->answers = [new MockResponse('', ['http_code' => 401]), new MockResponse(self::fixture('bulk_availability.json'))];

        $rows = $this->client()->fetchAll();

        self::assertSame(2, $this->tokensIssued);
        self::assertCount(3, $rows);
    }

    public function testAnEmptyListIsAFailureSoStockIsNeverZeroed(): void
    {
        $this->answers = [new MockResponse('{"products":[]}')];

        $this->expectException(OrsApiException::class);
        $this->expectExceptionMessage('returned no products');

        $this->client()->fetchAll();
    }

    public function testAServerErrorIsRetryable(): void
    {
        $this->answers = [new MockResponse('', ['http_code' => 503])];

        try {
            $this->client()->fetchAll();
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException $e) {
            self::assertStringContainsString('HTTP 503', $e->getMessage());
            self::assertTrue($e->retryable);
        }
    }

    public function testItRefusesToRunWithoutCredentials(): void
    {
        $this->expectException(OrsApiException::class);
        $this->expectExceptionMessage('not configured');

        (new BulkAvailabilityClient(new MockHttpClient(), new ArrayAdapter(), self::URL, '', '', ''))->fetchAll();
    }

    public function testRowsWithoutAnItemOrCountAreSkippedAndCountsAreNeverNegative(): void
    {
        $rows = BulkAvailabilityClient::parse(['products' => [
            ['orsProduct' => ' 312-7 ', 'uom' => 'EA', 'totalAvailability' => '15'],
            ['orsProduct' => 'NEG-1', 'uom' => 'EA', 'totalAvailability' => -4],
            ['orsProduct' => '', 'uom' => 'EA', 'totalAvailability' => 3],
            ['orsProduct' => 'NO-COUNT', 'uom' => 'EA'],
            'not a row',
        ]]);

        self::assertSame(['312-7', 'NEG-1'], array_keys($rows));
        self::assertSame(15, $rows['312-7']->totalAvailable);
        self::assertSame(0, $rows['NEG-1']->totalAvailable);
    }

    private function client(): BulkAvailabilityClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = [$method, $url, $options];

            if (self::TOKEN_URL === $url) {
                ++$this->tokensIssued;

                return new MockResponse((string) json_encode(['access_token' => 'token-'.$this->tokensIssued, 'expires_in' => 3600]));
            }

            return array_shift($this->answers) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new BulkAvailabilityClient($http, new ArrayAdapter(), self::URL, self::TOKEN_URL, 'client-id', 'client-secret');
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    /** @param array<mixed> $options */
    private static function body(array $options): string
    {
        $body = $options['body'] ?? '';

        return is_string($body) ? $body : '';
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private static function headers(array $options): array
    {
        $headers = $options['headers'] ?? [];

        return is_array($headers) ? array_values(array_filter($headers, 'is_string')) : [];
    }
}
