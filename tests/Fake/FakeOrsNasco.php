<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Supplier\OrsNasco\AvailabilityRow;
use App\Supplier\OrsNasco\BulkAvailabilityClientInterface;
use App\Supplier\OrsNasco\OrsApiException;
use App\Supplier\OrsNasco\PriceClientInterface;
use App\Supplier\OrsNasco\PriceRow;

/**
 * Stands in for both ORS Nasco APIs in the test environment
 * (config/services_test.yaml), so e2e tests never call ORS. Tests set what
 * it answers; the real HTTP/SOAP parsing is covered by unit tests.
 */
final class FakeOrsNasco implements BulkAvailabilityClientInterface, PriceClientInterface
{
    /** @var array<string, AvailabilityRow> */
    private array $availability = [];

    /** @var array<string, PriceRow> */
    private array $prices = [];

    private ?string $failWith = null;

    public function reset(): void
    {
        $this->availability = [];
        $this->prices = [];
        $this->failWith = null;
    }

    public function willHaveStock(string $sku, int $total, string $uom = 'EA'): void
    {
        $this->availability[$sku] = new AvailabilityRow($sku, $uom, $total);
    }

    public function willCost(string $sku, ?int $costInCents, ?string $error = null, string $unitSell = 'EA'): void
    {
        $this->prices[$sku] = new PriceRow($sku, $costInCents, $unitSell, '1 EA/'.$unitSell, $error);
    }

    public function willFail(string $message): void
    {
        $this->failWith = $message;
    }

    public function fetchAll(): array
    {
        if (null !== $this->failWith) {
            throw new OrsApiException($this->failWith);
        }

        return $this->availability;
    }

    public function fetch(array $itemNumbers): array
    {
        if (null !== $this->failWith) {
            throw new OrsApiException($this->failWith);
        }

        return array_intersect_key($this->prices, array_flip($itemNumbers));
    }
}
