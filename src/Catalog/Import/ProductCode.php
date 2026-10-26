<?php

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Sylius codes allow only letters, numbers, "-" and "_"; supplier SKUs also
 * use ".", "/" and "+". Those become "_": ORS "012-92-500-7.5-8" is product
 * code "012-92-500-7_5-8". The supplier's own SKU is kept on the product's
 * import state for the supplier syncs.
 */
final class ProductCode
{
    public static function fromSku(string $sku): string
    {
        return (string) preg_replace('/[^\w-]/', '_', $sku);
    }
}
