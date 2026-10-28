<?php

declare(strict_types=1);

namespace App\Catalog\Stock;

use App\Entity\Product\ProductVariant;

/**
 * The shop's stock for a SKU: the total of what its switched-on sources
 * have. Sources with no stock feed don't count; when none has a known
 * count, the variant is left as it is.
 */
final class VariantStockTotal
{
    /** @return ?int the new on-hand, or null when nothing is known (and nothing changed) */
    public static function apply(ProductVariant $variant): ?int
    {
        $total = null;
        foreach ($variant->getSources() as $source) {
            if ($source->isEnabled() && null !== $source->getStock()) {
                $total = ($total ?? 0) + $source->getStock();
            }
        }

        if (null !== $total) {
            $variant->setTracked(true);
            $variant->setOnHand($total);
        }

        return $total;
    }
}
