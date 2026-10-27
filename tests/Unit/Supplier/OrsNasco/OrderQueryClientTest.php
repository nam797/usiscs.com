<?php

declare(strict_types=1);

namespace App\Tests\Unit\Supplier\OrsNasco;

use App\Supplier\OrsNasco\OrderQueryClient;
use App\Supplier\OrsNasco\OrderSuffix;
use App\Supplier\OrsNasco\OrsApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OrderQueryClientTest extends TestCase
{
    private const URL = 'https://webservices.orsnasco.test/ORSOrderQuery.asmx';

    public function testAShippedAndInvoicedOrder(): void
    {
        $suffixes = OrderQueryClient::parse(self::fixture('order_query_invoiced.xml'));

        self::assertCount(1, $suffixes);
        $suffix = $suffixes[0];
        self::assertSame('12345678-12', $suffix->orderNumber);
        self::assertSame('12345678', $suffix->baseOrderNumber());
        self::assertSame('ABC123', $suffix->customerPo);
        self::assertSame(OrderSuffix::STAGE_INVOICED, $suffix->stage);
        self::assertFalse($suffix->held);
        self::assertFalse($suffix->isCancelled());
        self::assertSame('CIN', $suffix->warehouse);

        $line = $suffix->lines[0];
        self::assertSame('012-11-500-10', $line->item);
        self::assertSame('PR', $line->unit);
        self::assertSame([10, 10, 0, 10, 588], [$line->quantityOrdered, $line->quantityThisSuffix, $line->quantityBackordered, $line->quantityShipped, $line->unitPriceInCents]);

        self::assertSame([['shipVia' => 'UPSG', 'shipViaDescription' => 'UPS GRD COML', 'trackingNumber' => '1Z4704980326987203']], $suffix->shipments);
        self::assertSame('2022-10-03', $suffix->invoiceDate);
        self::assertSame(['Invoice Total' => 8070, 'Item Subtotal' => 5880, 'Freight Out' => 1655, 'Small Order' => 500, 'Fuel Surchg' => 35], $suffix->invoiceValuesInCents);
    }

    public function testEverySuffixOfASplitOrder(): void
    {
        $suffixes = OrderQueryClient::parse(self::fixture('order_query_cancelled_suffix.xml'));

        self::assertSame(['90803340-00', '90803340-01'], array_map(static fn (OrderSuffix $s): string => $s->orderNumber, $suffixes));
        self::assertTrue($suffixes[0]->isCancelled());
        self::assertSame([], $suffixes[0]->shipments);
        self::assertSame([], $suffixes[0]->invoiceValuesInCents);
        self::assertFalse($suffixes[1]->isCancelled());
    }

    public function testNoOrdersIsAnEmptyResult(): void
    {
        self::assertSame([], OrderQueryClient::parse(self::fixture('order_query_none.xml')));
    }

    public function testWrongCredentialsAreAFailureNotAnEmptyResult(): void
    {
        $this->expectException(OrsApiException::class);
        $this->expectExceptionMessage('check ORS_CUSTNO, ORS_OQ_PASSWORD and ORS_OQ_PASSWORD2');

        OrderQueryClient::parse(self::fixture('order_query_bad_credentials.xml'));
    }

    public function testAnInputErrorFromOrsIsReported(): void
    {
        try {
            OrderQueryClient::parse(self::fixture('order_query_error.xml'));
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException $e) {
            self::assertStringContainsString('Orderno format must be 8-digit number', $e->getMessage());
            self::assertFalse($e->retryable);
        }
    }

    public function testAnHtmlErrorPageIsARetryableFailure(): void
    {
        try {
            OrderQueryClient::parse(self::fixture('timeout.html'), 500);
            self::fail('Expected an OrsApiException');
        } catch (OrsApiException $e) {
            self::assertTrue($e->retryable);
        }
    }

    public function testTheRequestFollowsTheDocumentedEnvelope(): void
    {
        $xml = $this->client(new MockHttpClient())->envelope('000123-1 & <x>', new \DateTimeImmutable('2026-10-26 10:00'));

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('ors', 'http://webservices.orsnasco.com/ORSOrderQueryV1/ORSOrderQuery/');

        self::assertSame('OrderQuery', $xpath->evaluate('string(/soapenv:Envelope/soapenv:Body/ors:GetOrder/ors:querytype)'));
        self::assertSame('600005', $xpath->evaluate('string(//ors:GetOrder/ors:custno)'));
        self::assertSame('000123-1 & <x>', $xpath->evaluate('string(//ors:GetOrder/ors:custpo)'), 'escaped, not injected');
        self::assertSame('', $xpath->evaluate('string(//ors:GetOrder/ors:orderno)'));
        self::assertSame('2026-10-26', $xpath->evaluate('string(//ors:GetOrder/ors:startdate)'));
        self::assertSame('pass-1', $xpath->evaluate('string(//ors:GetOrder/ors:password)'));
        self::assertSame('pass-2', $xpath->evaluate('string(//ors:GetOrder/ors:password2)'));
    }

    public function testATimeoutIsRetriedAndTheSoapActionIsSent(): void
    {
        $requests = [];
        $responses = [
            new MockResponse(self::fixture('timeout.html'), ['http_code' => 500]),
            new MockResponse(self::fixture('order_query_invoiced.xml')),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = $options;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        $suffixes = $this->client($http)->findByCustomerPo('ABC123');

        self::assertCount(2, $requests);
        self::assertCount(1, $suffixes);
        $headers = $requests[0]['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertStringContainsString('ORSOrderQuery/GetOrder"', implode("\n", array_filter($headers, 'is_string')));
    }

    public function testItRefusesToRunWithoutCredentials(): void
    {
        $client = new OrderQueryClient(new MockHttpClient(), self::URL, '600005', '', '', 0);
        self::assertFalse($client->isConfigured());

        $this->expectException(OrsApiException::class);
        $this->expectExceptionMessage('not configured');
        $client->findByCustomerPo('ABC123');
    }

    private function client(MockHttpClient $http): OrderQueryClient
    {
        return new OrderQueryClient($http, self::URL, '600005', 'pass-1', 'pass-2', 0);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }
}
