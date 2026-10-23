<?php

declare(strict_types=1);

namespace App\Catalog\GoLive;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * What a product needs before it can be shown in the shop. Used by the
 * import's go_live column and by the admin "Go live" bulk action, so both
 * apply the same rule. Images are deliberately not required.
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
}
