<?php

declare(strict_types=1);

namespace App\Tests\Unit\Supplier\OrsNasco;

use App\Supplier\OrsNasco\OrsApiException;
use App\Supplier\OrsNasco\PriceClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PriceClientTest extends TestCase
{
    private const URL = 'https://webservices.orsnasco.test/ORSInventoryQueryTool.asmx';

    public function testPricesAreOurCostInCentsPerSellingUnit(): void
    {
        $rows = PriceClient::parse(self::fixture('price_only.xml'));

        self::assertSame(['312-7', '680-11-921A', 'DUMMYITEM'], array_keys($rows));
        self::assertSame(30, $rows['312-7']->costInCents, '0.302 rounds to 30 cents');
        self::assertSame(1243, $rows['680-11-921A']->costInCents);
        self::assertSame('PK', $rows['680-11-921A']->unitSell);
        self::assertSame('100 EA/PK', $rows['680-11-921A']->unitConversion);
        self::assertNull($rows['680-11-921A']->problem());
    }

    public function testAnUnknownItemHasNoCostAndSaysWhy(): void
    {
        $row = PriceClient::parse(self::fixture('price_only.xml'))['DUMMYITEM'];

        self::assertNull($row->costInCents, 'ORS sends 0 for an invalid item; that is not a cost');
        self::assertNull($row->unitSell);
        self::assertSame('Invalid Product Number', $row->problem());
    }

    public function testWrongCredentialsAreAFailureNotAnEmptyResult(): void
    {
        $this->expectException(OrsApiException::class);
        $this->expectExceptionMessage('check ORS_CUSTNO, ORS_PA_PASSWORD and ORS_PA_PASSWORD2');

        PriceClient::parse(self::fixture('bad_credentials.xml'));
    }

    public function testASetupErrorFromOrsIsReported(): void
    {
        try {
            PriceClient::parse(self::fixture('setup_error.xml'));
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException $e) {
            self::assertStringContainsString('Customer # Not Set Up in Customer Setup', $e->getMessage());
            self::assertFalse($e->retryable);
        }
    }

    public function testAnHtmlErrorPageIsARetryableFailure(): void
    {
        try {
            PriceClient::parse(self::fixture('timeout.html'), 500);
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException $e) {
            self::assertStringContainsString('did not answer with XML (HTTP 500)', $e->getMessage());
            self::assertTrue($e->retryable);
        }
    }

    public function testTheRequestFollowsTheDocumentedEnvelope(): void
    {
        $xml = $this->client(new MockHttpClient())->envelope(['312-7', 'A&B<1>']);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('ors', 'http://webservices.orsnasco.com/ORSInventoryQueryToolWSV3/ORSInventoryQueryTool/');

        self::assertSame('PriceOnlyV2', $xpath->evaluate('string(/soap:Envelope/soap:Body/ors:GetPA/ors:queryType)'));
        self::assertSame('600005', $xpath->evaluate('string(//ors:GetPA/ors:custno)'));
        self::assertSame('pass-1', $xpath->evaluate('string(//ors:GetPA/ors:password)'));
        self::assertSame('pass-2', $xpath->evaluate('string(//ors:GetPA/ors:password2)'));
        $items = [];
        foreach ($xpath->query('//ors:GetPA/ors:criteria/ors:string') ?: [] as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            $items[] = $node->textContent;
        }
        self::assertSame(['312-7', 'A&B<1>'], $items, 'Item numbers are escaped, not injected');
    }

    public function testItemsAreSentFortyPerRequest(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse(self::fixture('price_only.xml'));
        });

        $items = array_map(static fn (int $i): string => 'ITEM-'.$i, range(1, 85));
        $rows = $this->client($http)->fetch([...$items, 'ITEM-1']);

        self::assertCount(3, $requests, '85 distinct items = 40 + 40 + 5');
        self::assertSame(40, substr_count(self::body($requests[0]), '<string>'));
        self::assertSame(5, substr_count(self::body($requests[2]), '<string>'));
        self::assertStringContainsString('ORSInventoryQueryTool/GetPA"', implode("\n", self::headers($requests[0])));
        self::assertArrayHasKey('680-11-921A', $rows);
    }

    public function testATimeoutIsRetriedBeforeGivingUp(): void
    {
        $responses = [
            new MockResponse(self::fixture('timeout.html'), ['http_code' => 500]),
            new MockResponse(self::fixture('price_only.xml')),
        ];

        $rows = $this->client(new MockHttpClient($responses))->fetch(['312-7']);

        self::assertSame(30, $rows['312-7']->costInCents);
    }

    public function testItStopsAfterThreeFailedAttempts(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::fixture('timeout.html'), ['http_code' => 500]);
        });

        try {
            $this->client($http)->fetch(['312-7']);
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException) {
            self::assertSame(3, $calls);
        }
    }

    public function testWrongCredentialsAreNotRetried(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::fixture('bad_credentials.xml'));
        });

        try {
            $this->client($http)->fetch(['312-7']);
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException) {
            self::assertSame(1, $calls);
        }
    }

    public function testItRefusesToRunWithoutCredentials(): void
    {
        $this->expectException(OrsApiException::class);
        $this->expectExceptionMessage('not configured');

        (new PriceClient(new MockHttpClient(), self::URL, '600005', '', '', 0))->fetch(['312-7']);
    }

    private function client(MockHttpClient $http): PriceClient
    {
        return new PriceClient($http, self::URL, '600005', 'pass-1', 'pass-2', 0);
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
