<?php

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Catalog\SupplierItem;
use App\Catalog\Unit\PackUnit;
use App\Command\SetupCategoriesCommand;

/**
 * One validated product from the go-live spreadsheet or the supplier feed.
 *
 * Columns are matched by header name, so their order in the sheet doesn't
 * matter. Optional columns that are missing from a sheet entirely are "not
 * provided" (the import leaves those fields alone); an optional column that
 * is present but blank means "no value".
 */
final class ProductRow
{
    public const REQUIRED_COLUMNS = ['sku', 'name', 'category', 'price'];

    public const OPTIONAL_COLUMNS = [
        'description',
        'short_description',
        'manufacturer',
        'part_number',
        'unit_of_measure',
        'stock_qty',
        'image_url',
        'go_live',
        'group',
        'variant_name',
        'fulfilment',
        'shipping_note',
        'distributor',
        'supplier_sku',
    ];

    /** Supplier item numbers: letters, numbers and - _ . / + (ORS uses all of them). */
    private const SKU_PATTERN = '/^[\w.\/+-]+$/';

    /**
     * @param list<string> $provided columns present in the source, for optional fields
     */
    private function __construct(
        /** the Sylius product/variant code (see ProductCode) */
        public readonly string $sku,
        /** the SKU as written in the sheet, e.g. ORS's "012-92-500-7.5-8" */
        public readonly string $supplierSku,
        public readonly string $name,
        public readonly string $categoryCode,
        public readonly int $priceInCents,
        public readonly ?string $description,
        public readonly ?string $shortDescription,
        public readonly ?string $manufacturer,
        public readonly ?string $partNumber,
        public readonly ?PackUnit $unitOfMeasure,
        public readonly ?int $stockQty,
        public readonly ?string $imageUrl,
        public readonly bool $goLive,
        /** product code shared by the SKUs sold as one product (sizes, colours); null for a product of its own */
        public readonly ?string $group,
        /** what tells this SKU apart within its group, e.g. "Size 10, black" */
        public readonly ?string $variantName,
        /** ORS direct / cross-dock; blank means not confirmed */
        public readonly Fulfilment $fulfilment,
        public readonly ?string $shippingNote,
        /** who this sheet's SKU is from, as a distributor code ("ors_nasco"); ORS Nasco when blank */
        public readonly string $distributor,
        /** the distributor's own item number when it isn't the sheet's SKU (supplier_sku column) */
        public readonly ?string $distributorSku,
        private readonly array $provided,
    ) {
    }

    /**
     * @param array<string, string> $values column name => cell text
     *
     * @throws InvalidProductRow
     */
    public static function fromArray(array $values): self
    {
        $problems = [];
        $text = static fn (string $column): string => trim($values[$column] ?? '');
        $optional = static fn (string $column): ?string => '' === $text($column) ? null : $text($column);

        $sku = $text('sku');
        if ('' === $sku) {
            $problems[] = 'sku is required';
        } elseif (!preg_match(self::SKU_PATTERN, $sku) || strlen($sku) > 255) {
            $problems[] = sprintf('sku "%s" may only contain letters, numbers, "-", "_", ".", "/" and "+" (max 255)', $sku);
        }

        $name = $text('name');
        if ('' === $name) {
            $problems[] = 'name is required';
        } elseif (mb_strlen($name) > 255) {
            $problems[] = 'name is longer than 255 characters';
        }

        $categoryCode = self::categoryCode($text('category'));
        if (null === $categoryCode) {
            $problems[] = '' === $text('category')
                ? 'category is required'
                : sprintf('unknown category "%s" (use one of: %s)', $text('category'), implode(', ', SetupCategoriesCommand::CATEGORIES));
        }

        $price = self::priceInCents($text('price'));
        if (null === $price) {
            $problems[] = '' === $text('price')
                ? 'price is required'
                : sprintf('price "%s" is not a valid amount (e.g. 12.99)', $text('price'));
        } elseif ($price <= 0) {
            $problems[] = 'price must be greater than 0';
        }

        $unitOfMeasure = null;
        if (null !== $optional('unit_of_measure')) {
            try {
                $unitOfMeasure = PackUnit::parse($text('unit_of_measure'));
            } catch (\InvalidArgumentException $e) {
                $problems[] = sprintf('unit_of_measure: %s', $e->getMessage());
            }
        }

        foreach (['manufacturer', 'part_number'] as $column) {
            if (mb_strlen($text($column)) > 255) {
                $problems[] = sprintf('%s is longer than 255 characters', $column);
            }
        }

        $stockQty = null;
        if (null !== $optional('stock_qty')) {
            if (!preg_match('/^\d+$/', $text('stock_qty'))) {
                $problems[] = sprintf('stock_qty "%s" must be a whole number of 0 or more', $text('stock_qty'));
            } else {
                $stockQty = (int) $text('stock_qty');
            }
        }

        $imageUrl = $optional('image_url');
        if (null !== $imageUrl && !self::isHttpUrl($imageUrl)) {
            $problems[] = sprintf('image_url "%s" must be an http(s) link', $imageUrl);
        }

        $group = $optional('group');
        if (null !== $group && (!preg_match(self::SKU_PATTERN, $group) || strlen($group) > 255)) {
            $problems[] = sprintf('group "%s" may only contain letters, numbers, "-", "_", ".", "/" and "+" (max 255)', $group);
        }
        $variantName = $optional('variant_name');
        if (null !== $group && null === $variantName) {
            $problems[] = 'variant_name is required for a SKU in a group (e.g. "Size 10, black")';
        } elseif (null !== $variantName && mb_strlen($variantName) > 255) {
            $problems[] = 'variant_name is longer than 255 characters';
        }

        $fulfilment = Fulfilment::Unconfirmed;
        try {
            $fulfilment = Fulfilment::parse($text('fulfilment'));
        } catch (\InvalidArgumentException $e) {
            $problems[] = sprintf('fulfilment: %s', $e->getMessage());
        }
        if (mb_strlen($text('shipping_note')) > 255) {
            $problems[] = 'shipping_note is longer than 255 characters';
        }

        // "ORS Nasco" or "ors_nasco"; the importer checks it's a distributor we have.
        $distributor = (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text('distributor')));
        $distributor = '' === trim($distributor, '_') ? SupplierItem::ORS_NASCO : trim($distributor, '_');
        if (strlen($distributor) > 50) {
            $problems[] = 'distributor is longer than 50 characters';
        }

        if (strlen($text('supplier_sku')) > 255) {
            $problems[] = 'supplier_sku is longer than 255 characters';
        }

        $goLive = self::yesNo($text('go_live'));
        if (null === $goLive) {
            $problems[] = sprintf('go_live "%s" must be yes or no', $text('go_live'));
        }

        if ([] !== $problems) {
            throw new InvalidProductRow($problems);
        }

        \assert(null !== $categoryCode && null !== $price && null !== $goLive);

        return new self(
            ProductCode::fromSku($sku),
            $sku,
            $name,
            $categoryCode,
            $price,
            $optional('description'),
            $optional('short_description'),
            $optional('manufacturer'),
            $optional('part_number'),
            $unitOfMeasure,
            $stockQty,
            $imageUrl,
            $goLive,
            null === $group ? null : ProductCode::fromSku($group),
            $variantName,
            $fulfilment,
            $optional('shipping_note'),
            $distributor,
            $optional('supplier_sku'),
            array_values(array_intersect(self::OPTIONAL_COLUMNS, array_keys($values))),
        );
    }

    public function provides(string $optionalColumn): bool
    {
        return in_array($optionalColumn, $this->provided, true);
    }

    /** Accepts a category code or its name, case-insensitively. */
    private static function categoryCode(string $value): ?string
    {
        $needle = mb_strtolower($value);
        foreach (SetupCategoriesCommand::CATEGORIES as $code => $name) {
            if ($needle === $code || $needle === mb_strtolower($name)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * "12.99", "$1,299.00", "12" -> cents. Parsed as text (never via float)
     * so prices can't pick up rounding errors.
     */
    private static function priceInCents(string $value): ?int
    {
        $value = str_replace([' ', '$'], '', $value);
        if (!preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return null;
        }

        $dollars = (int) str_replace(',', '', $matches[1]);
        $cents = (int) str_pad($matches[2] ?? '0', 2, '0');

        return $dollars * 100 + $cents;
    }

    private static function yesNo(string $value): ?bool
    {
        return match (mb_strtolower($value)) {
            '', 'no', 'n', 'false', '0' => false,
            'yes', 'y', 'true', '1' => true,
            default => null,
        };
    }

    private static function isHttpUrl(string $value): bool
    {
        $scheme = parse_url($value, \PHP_URL_SCHEME);

        return false !== filter_var($value, \FILTER_VALIDATE_URL)
            && is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
