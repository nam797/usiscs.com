<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Product\ProductVariant;

/**
 * Which distributor an order line goes to, when its SKU has several
 * sources: the cheapest one that has enough stock.
 *
 *  - Only sources that are switched on with a confirmed fulfilment count.
 *  - Never at a loss: a source whose cost is at or above what the customer
 *    paid is passed over.
 *  - In stock first, then unknown stock (no feed), then short of stock;
 *    within each, the lowest known cost (unknown cost last), then direct
 *    before cross-dock, then the distributor code, so the choice is stable.
 *  - A line is never split: if nobody has enough, the cheapest still gets
 *    it and back-orders, and the reason says so.
 *
 * Nothing usable: no choice, and the planner sends the line to "needs
 * attention" as before.
 */
final class SourceSelector
{
    private const IN_STOCK = 0;
    private const STOCK_UNKNOWN = 1;
    private const SHORT = 2;

    public function __construct(private readonly DistributorRegistry $distributors)
    {
    }

    public function choose(ProductVariant $variant, int $quantity, int $unitPriceInCents): SourceChoice
    {
        $candidates = [];
        $passedOver = [];
        foreach ($variant->getSources() as $source) {
            if (!$source->isUsable()) {
                continue;
            }
            $cost = $source->getCostInCents();
            if (null !== $cost && $cost >= $unitPriceInCents) {
                $passedOver[] = sprintf('%s %s is not below the price paid', $this->distributors->nameOf($source->getSupplier()), self::money($cost));
                continue;
            }
            $candidates[] = $source;
        }

        if ([] === $candidates) {
            return new SourceChoice(null, [] === $passedOver ? 'no source with a confirmed fulfilment' : implode('; ', $passedOver));
        }

        usort($candidates, static fn (SupplierItem $a, SupplierItem $b): int => self::rank($a, $quantity) <=> self::rank($b, $quantity));
        $chosen = $candidates[0];
        $short = self::SHORT === self::stockClass($chosen, $quantity);

        $others = array_map(fn (SupplierItem $s): string => $this->describe($s), array_slice($candidates, 1));
        $reason = 1 === count($candidates)
            ? sprintf('%s, the only source', $this->describe($chosen))
            : sprintf('%s over %s', $this->describe($chosen), implode(', ', $others));
        if ($short) {
            $reason .= sprintf('. No source has %d in stock: expect a back-order', $quantity);
        }
        if ([] !== $passedOver) {
            $reason .= '. Passed over: '.implode('; ', $passedOver);
        }

        return new SourceChoice($chosen, $reason, $short);
    }

    /** @return list<int|string> */
    private static function rank(SupplierItem $source, int $quantity): array
    {
        $cost = $source->getCostInCents();

        return [
            self::stockClass($source, $quantity),
            null === $cost ? 1 : 0,
            $cost ?? 0,
            Fulfilment::OrsDirect === $source->getFulfilment() ? 0 : 1,
            $source->getSupplier(),
        ];
    }

    private static function stockClass(SupplierItem $source, int $quantity): int
    {
        $stock = $source->getStock();

        return match (true) {
            null === $stock => self::STOCK_UNKNOWN,
            $stock >= $quantity => self::IN_STOCK,
            default => self::SHORT,
        };
    }

    private function describe(SupplierItem $source): string
    {
        return sprintf(
            '%s %s (stock %s)',
            $this->distributors->nameOf($source->getSupplier()),
            null === $source->getCostInCents() ? 'cost unknown' : self::money($source->getCostInCents()),
            null === $source->getStock() ? 'unknown' : (string) $source->getStock(),
        );
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
