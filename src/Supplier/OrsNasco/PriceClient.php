<?php

declare(strict_types=1);

namespace App\Supplier\OrsNasco;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ORS Nasco "Price & Availability Webservice" (SOAP, rev. 8/13/2025), used
 * for our account's price (= our cost) per ORS selling unit.
 *
 * Uses the PriceOnlyV2 query, which ORS scales to 40 items per request.
 * The envelope is built and read with DOM rather than ext-soap: ORS
 * documents the exact XML, and this keeps it testable without a WSDL.
 */
final class PriceClient implements PriceClientInterface
{
    public const MAX_ITEMS_PER_REQUEST = 40;

    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const ORS_NS = 'http://webservices.orsnasco.com/ORSInventoryQueryToolWSV3/ORSInventoryQueryTool/';
    private const QUERY_TYPE = 'PriceOnlyV2';
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'ORS_PA_URL')]
        private readonly string $url,
        #[Autowire(env: 'ORS_CUSTNO')]
        private readonly string $customerNumber,
        #[Autowire(env: 'ORS_PA_PASSWORD')]
        private readonly string $password,
        #[Autowire(env: 'ORS_PA_PASSWORD2')]
        private readonly string $password2,
        private readonly int $retryDelayMs = 2000,
    ) {
    }

    public function fetch(array $itemNumbers): array
    {
        if ('' === $this->url || '' === $this->customerNumber || '' === $this->password || '' === $this->password2) {
            throw new OrsApiException('ORS price service is not configured: set ORS_CUSTNO, ORS_PA_PASSWORD and ORS_PA_PASSWORD2');
        }

        $rows = [];
        foreach (array_chunk(array_values(array_unique($itemNumbers)), self::MAX_ITEMS_PER_REQUEST) as $chunk) {
            $rows += $this->fetchChunk($chunk);
        }

        return $rows;
    }

    /**
     * @param list<string> $itemNumbers at most MAX_ITEMS_PER_REQUEST
     *
     * @return array<string, PriceRow>
     */
    private function fetchChunk(array $itemNumbers): array
    {
        $envelope = $this->envelope($itemNumbers);

        for ($attempt = 1; ; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $this->url, [
                    'headers' => [
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"'.self::ORS_NS.'GetPA"',
                    ],
                    'body' => $envelope,
                    'timeout' => 60,
                ]);

                return self::parse($response->getContent(false), $response->getStatusCode());
            } catch (ExceptionInterface $e) {
                $failure = new OrsApiException(sprintf('ORS price service failed: %s', $e->getMessage()), true, $e);
            } catch (OrsApiException $e) {
                $failure = $e;
            }

            if (!$failure->retryable || $attempt >= self::ATTEMPTS) {
                throw $failure;
            }
            usleep($this->retryDelayMs * 1000 * $attempt);
        }
    }

    /** @param list<string> $itemNumbers */
    public function envelope(array $itemNumbers): string
    {
        $doc = new \DOMDocument('1.0', 'utf-8');
        $envelope = $doc->appendChild($doc->createElementNS(self::SOAP_NS, 'SOAP-ENV:Envelope'));
        \assert($envelope instanceof \DOMElement);
        $envelope->setAttributeNS(self::SOAP_NS, 'SOAP-ENV:encodingStyle', 'http://schemas.xmlsoap.org/soap/encoding/');
        $body = $envelope->appendChild($doc->createElementNS(self::SOAP_NS, 'SOAP-ENV:Body'));
        $getPa = $body->appendChild($doc->createElementNS(self::ORS_NS, 'GetPA'));

        $text = static function (\DOMNode $parent, string $name, string $value) use ($doc): void {
            $element = $doc->createElementNS(self::ORS_NS, $name);
            $element->textContent = $value; // escapes &, < and friends
            $parent->appendChild($element);
        };

        $text($getPa, 'queryType', self::QUERY_TYPE);
        $text($getPa, 'custno', $this->customerNumber);
        $criteria = $getPa->appendChild($doc->createElementNS(self::ORS_NS, 'criteria'));
        foreach ($itemNumbers as $itemNumber) {
            $text($criteria, 'string', $itemNumber);
        }
        $text($getPa, 'password', $this->password);
        $text($getPa, 'password2', $this->password2);

        return (string) $doc->saveXML();
    }

    /**
     * @return array<string, PriceRow>
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
            throw new OrsApiException(sprintf('ORS price service did not answer with XML (HTTP %d)', $status), true);
        }

        $xpath = new \DOMXPath($doc);

        $fault = self::text($xpath, "//*[local-name()='Fault']/*[local-name()='faultstring']");
        if (null !== $fault) {
            throw new OrsApiException(sprintf('ORS price service fault: %s', $fault), $status >= 500);
        }

        $result = self::elements($xpath, "//*[local-name()='GetPAResult']")[0] ?? null;
        if (null === $result) {
            if ($status >= 500) {
                throw new OrsApiException(sprintf('ORS price service answered HTTP %d', $status), true);
            }
            // What ORS sends for a wrong account number or passwords.
            throw new OrsApiException('ORS price service returned no data; check ORS_CUSTNO, ORS_PA_PASSWORD and ORS_PA_PASSWORD2');
        }

        $error = self::text($xpath, "*[local-name()='Error']", $result);
        if (null !== $error) {
            throw new OrsApiException(sprintf('ORS price service error: %s', $error));
        }

        $rows = [];
        foreach (self::elements($xpath, ".//*[local-name()='ProductDetails']", $result) as $details) {
            $item = self::text($xpath, "*[local-name()='productCode']", $details);
            if (null === $item) {
                continue;
            }

            $rows[$item] = new PriceRow(
                $item,
                self::cents(self::text($xpath, "*[local-name()='price']", $details)),
                self::text($xpath, "*[local-name()='unitSell']", $details),
                self::text($xpath, "*[local-name()='unitConversion']", $details),
                self::text($xpath, "*[local-name()='errorMessage']", $details),
            );
        }

        return $rows;
    }

    /** ORS prices have 3 decimals ("12.432"); rounded to the nearest cent. 0 means "no price". */
    private static function cents(?string $price): ?int
    {
        if (null === $price || 1 !== preg_match('/^\d+(\.\d+)?$/', $price)) {
            return null;
        }

        $cents = (int) round((float) $price * 100);

        return $cents > 0 ? $cents : null;
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
