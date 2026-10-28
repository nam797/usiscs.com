<?php

declare(strict_types=1);

namespace App\Catalog\GoLive;

use App\Entity\Catalog\SupplierItem;
use App\Entity\Product\ProductVariant;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * What a product needs before it can be shown in the shop. Used by the
 * import's go_live column and by the admin "Go live" bulk action, so both
 * apply the same rule. Images are deliberately not required.
 *
 * Every SKU needs a source it can be ordered from: a distributor that's
 * switched on with a confirmed fulfilment (direct or cross-dock).
 *
 * The selling price must be above every switched-on source's known cost,
 * so nothing goes live at a loss whichever distributor ends up with the
 * order.
 */
final class ReadinessChecker
{

    /** @return list<string> human-readable reasons; empty means ready */
    public function problems(ProductInterface $product): array
    {
        $problems = [];

        if (null === $product->getName() || '' === trim($product->getName())) {
            $problems[] = 'no name';
        }

        if (null === $product->getMainTaxon() && $product->getProductTaxons()->isEmpty()) {
            $problems[] = 'no category';
        }

        $channels = $product->getChannels();
        if ($channels->isEmpty()) {
            $problems[] = 'not in any sales channel';
        }

        foreach ($channels as $channel) {
            if (!$this->hasPriceIn($product, (string) $channel->getCode())) {
                $problems[] = sprintf('no price in channel "%s"', $channel->getCode());
            }
        }

        // Nothing is sold until we know who sends it and how it gets to the customer.
        $unconfirmed = [];
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant || [] === array_filter($variant->getSources()->toArray(), static fn (SupplierItem $s): bool => $s->isUsable())) {
                $unconfirmed[] = (string) $variant->getCode();
            }
        }
        if ([] !== $unconfirmed) {
            $problems[] = 1 === $product->getVariants()->count()
                ? 'shipping not confirmed (ORS direct or cross-dock)'
                : sprintf('shipping not confirmed (ORS direct or cross-dock) for %s', implode(', ', $unconfirmed));
        }

        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }
            foreach ($variant->getSources() as $source) {
                $cost = $source->getCostInCents();
                if ($source->isEnabled() && null !== $cost) {
                    array_push($problems, ...self::belowCost($product, $variant, $cost));
                }
            }
        }

        return $problems;
    }

    /**
     * Channels where the variant sells at or below what it costs us.
     *
     * @return list<string>
     */
    public static function belowCost(ProductInterface $product, ProductVariantInterface $variant, int $costInCents): array
    {
        $problems = [];
        foreach ($product->getChannels() as $channel) {
            foreach ($variant->getChannelPricings() as $pricing) {
                $price = $pricing->getPrice();
                if ($pricing->getChannelCode() === $channel->getCode() && null !== $price && $price > 0 && $price <= $costInCents) {
                    $problems[] = sprintf('price %s in channel "%s" is not above the supplier cost %s', self::money($price), $channel->getCode(), self::money($costInCents));
                }
            }
        }

        return $problems;
    }

    private function hasPriceIn(ProductInterface $product, string $channelCode): bool
    {
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }
            foreach ($variant->getChannelPricings() as $pricing) {
                if ($pricing->getChannelCode() === $channelCode && ($pricing->getPrice() ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
