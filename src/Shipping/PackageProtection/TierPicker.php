<?php

declare(strict_types=1);

namespace App\Shipping\PackageProtection;

use App\Entity\Shipping\PackageProtectionTier;
use App\Repository\Shipping\PackageProtectionTierRepository;

/**
 * The package protection fee for a cart: the tier with the highest "from"
 * amount the cart's items total reaches. Below the lowest tier (or with no
 * tiers) protection isn't offered.
 */
final class TierPicker
{
    public function __construct(
        private readonly PackageProtectionTierRepository $tiers,
    ) {
    }

    /** @return int|null the fee in cents, null when not offered */
    public function feeFor(int $itemsTotal): ?int
    {
        return self::pick($this->tiers->findAllInOrder(), $itemsTotal)?->getFee();
    }

    /** @param iterable<PackageProtectionTier> $tiers in any order */
    public static function pick(iterable $tiers, int $itemsTotal): ?PackageProtectionTier
    {
        $picked = null;
        foreach ($tiers as $tier) {
            if ($tier->getMinItemsTotal() <= $itemsTotal && (null === $picked || $tier->getMinItemsTotal() > $picked->getMinItemsTotal())) {
                $picked = $tier;
            }
        }

        return $picked;
    }
}
