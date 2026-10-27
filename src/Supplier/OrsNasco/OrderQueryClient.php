<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ORS Nasco "Order Query Webservice" (SOAP, rev. 5/8/2023): an order's
 * stage, hold flag, lines with shipped and back-ordered quantities,
 * tracking numbers and invoice totals. Looked up by our PO number
 * ("custpo"), which is how we find orders staff placed by hand and check
 * whether a timed-out attempt went through.
 *
 * Built and read with DOM like PriceClient. Its passwords are its own: each
 * ORS web service has a separate pair.
 */
final class OrderQueryClient implements OrderQueryClientInterface
{
    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const ORS_NS = 'http://webservices.orsnasco.com/ORSOrderQueryV1/ORSOrderQuery/';
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'ORS_ORDER_QUERY_URL')]
        private readonly string $url,
        #[Autowire(env: 'ORS_CUSTNO')]
        private readonly string $customerNumber,
        #[Autowire(env: 'ORS_OQ_PASSWORD')]
        private readonly string $password,
        #[Autowire(env: 'ORS_OQ_PASSWORD2')]
        private readonly string $password2,
        private readonly int $retryDelayMs = 2000,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->url && '' !== $this->customerNumber && '' !== $this->password && '' !== $this->password2;
    }

    public function findByCustomerPo(string $customerPo, ?\DateTimeImmutable $placedAfter = null): array
    {
        if (!$this->isConfigured()) {
            throw new OrsApiException('ORS order query service is not configured: set ORS_CUSTNO, ORS_OQ_PASSWORD and ORS_OQ_PASSWORD2');
        }

        $envelope = $this->envelope($customerPo, $placedAfter);
        for ($attempt = 1; ; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $this->url, [
                    'headers' => [
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"'.self::ORS_NS.'GetOrder"',
                    ],
                    'body' => $envelope,
                    'timeout' => 60,
                ]);

                return self::parse($response->getContent(false), $response->getStatusCode());
            } catch (ExceptionInterface $e) {
                $failure = new OrsApiException(sprintf('ORS order query service failed: %s', $e->getMessage()), true, $e);
            } catch (OrsApiException $e) {
                $failure = $e;
            }

            if (!$failure->retryable || $attempt >= self::ATTEMPTS) {
                throw $failure;
            }
            usleep($this->retryDelayMs * 1000 * $attempt);
        }
    }

    public function envelope(string $customerPo, ?\DateTimeImmutable $placedAfter = null): string
    {
        $doc = new \DOMDocument('1.0', 'utf-8');
        $envelope = $doc->appendChild($doc->createElementNS(self::SOAP_NS, 'soapenv:Envelope'));
        $envelope->appendChild($doc->createElementNS(self::SOAP_NS, 'soapenv:Header'));
        $body = $envelope->appendChild($doc->createElementNS(self::SOAP_NS, 'soapenv:Body'));
        $getOrder = $body->appendChild($doc->createElementNS(self::ORS_NS, 'ors:GetOrder'));

        $text = static function (string $name, string $value) use ($doc, $getOrder): void {
            $element = $doc->createElementNS(self::ORS_NS, 'ors:'.$name);
            $element->textContent = $value; // escapes &, < and friends
            $getOrder->appendChild($element);
        };

        $text('querytype', 'OrderQuery');
        $text('custno', $this->customerNumber);
        $text('orderno', '');
        $text('ordersuf', '');
        $text('custpo', $customerPo);
        $text('startdate', $placedAfter?->format('Y-m-d') ?? '');
        $text('enddate', '');
        $text('password', $this->password);
        $text('password2', $this->password2);

        return (string) $doc->saveXML();
    }

    /**
     * @return list<OrderSuffix>
     *
     * @throws OrsApiException
     */
    public static function parse(string $xml, int $status = 200): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // No LIBXML_NOENT: entities are never expanded, and NONET blocks fetching DTDs.
        $loaded = '' !== trim($xml) && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // ORS's ASP.NET error page (e.g. "Request timed out.") lands here.
            throw new OrsApiException(sprintf('ORS order query service did not answer with XML (HTTP %d)', $status), true);
        }

        $xpath = new \DOMXPath($doc);

        $fault = self::text($xpath, "//*[local-name()='Fault']/*[local-name()='faultstring']");
        if (null !== $fault) {
            throw new OrsApiException(sprintf('ORS order query service fault: %s', $fault), $status >= 500);
        }

        $result = self::elements($xpath, "//*[local-name()='GetOrderResult']")[0] ?? null;
        if (null === $result) {
            if ($status >= 500) {
                throw new OrsApiException(sprintf('ORS order query service answered HTTP %d', $status), true);
            }
            // What ORS sends for a wrong account number or passwords.
            throw new OrsApiException('ORS order query service returned no data; check ORS_CUSTNO, ORS_OQ_PASSWORD and ORS_OQ_PASSWORD2');
        }

        $error = self::text($xpath, ".//*[local-name()='OrderQuery']/*[local-name()='Error']", $result);
        if (null !== $error) {
            // ORS's documentation doesn't say what an unknown PO looks like;
            // assume an error that says nothing was found means "none".
            if (1 === preg_match('/\b(no|not)\b.*\bfound\b|\bno orders?\b/i', $error)) {
                return [];
            }
            throw new OrsApiException(sprintf('ORS order query service error: %s', $error));
        }

        $suffixes = [];
        foreach (self::elements($xpath, ".//*[local-name()='OrderDetails']", $result) as $details) {
            $orderNumber = self::text($xpath, "*[local-name()='OrderNo']", $details);
            if (null === $orderNumber) {
                continue;
            }

            $lines = [];
            foreach (self::elements($xpath, "*[local-name()='OrderLines']/*[local-name()='OrderLine']", $details) as $line) {
                $item = self::text($xpath, "*[local-name()='ORSItem']", $line);
                if (null === $item) {
                    continue;
                }
                $lines[] = new OrderSuffixLine(
                    $item,
                    self::text($xpath, "*[local-name()='UOM']", $line),
                    self::int(self::text($xpath, "*[local-name()='QtyOrdered']", $line)),
                    self::int(self::text($xpath, "*[local-name()='QtyThisSuffix']", $line)),
                    self::int(self::text($xpath, "*[local-name()='QtyBackorder']", $line)),
                    self::int(self::text($xpath, "*[local-name()='QtyShipped']", $line)),
                    self::cents(self::text($xpath, "*[local-name()='ItemPrice']", $line)),
                );
            }

            $shipments = [];
            foreach (self::elements($xpath, "*[local-name()='Shipments']/*[local-name()='Shipment']", $details) as $shipment) {
                $trackingNumber = self::text($xpath, "*[local-name()='TrackNo']", $shipment);
                if (null === $trackingNumber) {
                    continue;
                }
                $shipments[] = [
                    'shipVia' => self::text($xpath, "*[local-name()='ShipVia']", $shipment) ?? '',
                    'shipViaDescription' => self::text($xpath, "*[local-name()='ShipViaDesc']", $shipment) ?? '',
                    'trackingNumber' => $trackingNumber,
                ];
            }

            $invoiceValues = [];
            foreach (self::elements($xpath, "*[local-name()='InvoiceDetails']/*[local-name()='InvoiceValue']", $details) as $value) {
                $cents = self::cents(trim($value->textContent));
                if ('' !== $value->getAttribute('type') && null !== $cents) {
                    $invoiceValues[$value->getAttribute('type')] = $cents;
                }
            }

            $suffixes[] = new OrderSuffix(
                $orderNumber,
                self::text($xpath, "*[local-name()='CustPO']", $details),
                self::int(self::text($xpath, "*[local-name()='Stage']", $details)),
                'Y' === strtoupper((string) self::text($xpath, "*[local-name()='Held']", $details)),
                self::text($xpath, "*[local-name()='EnterDate']", $details),
                self::text($xpath, "*[local-name()='Warehouse']", $details),
                $lines,
                $shipments,
                self::text($xpath, "*[local-name()='InvoiceDetails']/*[local-name()='InvoiceDate']", $details),
                $invoiceValues,
            );
        }

        return $suffixes;
    }

    private static function int(?string $value): int
    {
        return null !== $value && 1 === preg_match('/^-?\d+(\.0+)?$/', $value) ? (int) $value : 0;
    }

    private static function cents(?string $amount): ?int
    {
        if (null === $amount || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            return null;
        }

        return (int) round((float) $amount * 100);
    }

    /** @return list<\DOMElement> */
    private static function elements(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
    {
        $elements = [];
        foreach ($xpath->query($expression, $context) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function text(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): ?string
    {
        $elements = self::elements($xpath, $expression, $context);
        $value = [] === $elements ? '' : trim($elements[0]->textContent);

        return '' === $value ? null : $value;
    }
}
