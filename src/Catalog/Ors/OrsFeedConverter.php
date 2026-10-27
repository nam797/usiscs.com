<?php

declare(strict_types=1);

namespace App\Catalog\Ors;

use App\Catalog\Unit\PackUnit;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Turns the ORS price feed spreadsheet (and, optionally, the SKU review
 * sheet with product families) into rows for app:catalog:import:
 *
 * - price = ORS list price per selling unit, rounded to the cent
 * - unit_of_measure = ORS's conversion ("12 PR = 1 DZ")
 * - SKUs of one product family with more than one priced SKU share a
 *   group, with a first-draft variant_name from VariantNamer
 * - fulfilment from the review sheet's "ORS direct ship?" (Yes = ORS direct,
 *   No = cross-dock, blank = not confirmed), and a shipping note for SKUs
 *   its preliminary screen flagged (aerosols, chemicals, oversize, ...)
 * - everything arrives as a hidden draft (go_live = no)
 *
 * SKUs ORS has no price for are skipped and listed.
 */
final class OrsFeedConverter
{
    public const FEED_SHEET = 'ORS price feed';
    public const REVIEW_SHEET = 'SKU review';

    /** Import columns, then columns for whoever reviews the sheet (the import ignores those). */
    public const COLUMNS = [
        'sku', 'name', 'category', 'price', 'manufacturer', 'part_number', 'unit_of_measure',
        'group', 'variant_name', 'go_live', 'fulfilment', 'shipping_note',
        'review', 'ors_description', 'segment', 'launch_tier',
    ];

    /**
     * @return array{rows: list<array<string, string>>, skipped: list<array{sku: string, reason: string}>}
     */
    public function convert(string $feedPath, ?string $reviewPath = null): array
    {
        $families = null === $reviewPath ? [] : $this->families($reviewPath);

        $priced = [];
        $skipped = [];
        foreach (self::sheetRows($feedPath, self::FEED_SHEET) as $row) {
            $sku = $row['ors sku'] ?? '';
            if ('' === $sku) {
                continue;
            }
            $list = $row['list / standard pack'] ?? '';
            if (!is_numeric($list) || (float) $list <= 0) {
                $skipped[] = ['sku' => $sku, 'reason' => sprintf('no ORS list price (%s)', '' === ($row['feed status'] ?? '') ? 'blank' : $row['feed status'])];
                continue;
            }
            $unitText = '' !== ($row['uom conversion'] ?? '') ? $row['uom conversion'] : ($row['cost uom'] ?? '');
            try {
                $unit = PackUnit::parse($unitText)->toString();
            } catch (\InvalidArgumentException $e) {
                $skipped[] = ['sku' => $sku, 'reason' => sprintf('unit of measure: %s', $e->getMessage())];
                continue;
            }

            $family = $families[$sku] ?? null;
            $priced[$sku] = [
                'sku' => $sku,
                'name' => self::clean($family['family'] ?? (string) preg_replace('/\s*\[ORS [^\]]*\]\s*$/', '', $row['selected product'] ?? $sku)),
                'category' => InterimCategory::for($row['usiscs segment'] ?? '', $family['family'] ?? ($row['selected product'] ?? '')),
                'price' => self::cents((string) $list),
                'manufacturer' => self::clean($family['brand'] ?? '') ?: self::brand($row['ors brand'] ?? ''),
                'part_number' => $row['vendor part number'] ?? '',
                'unit_of_measure' => $unit,
                'group' => '',
                'variant_name' => '',
                'go_live' => 'no',
                'fulfilment' => match (strtolower($family['direct_ship'] ?? '')) {
                    'yes', 'y' => 'ORS direct',
                    'no', 'n' => 'cross-dock',
                    default => '',
                },
                'shipping_note' => $family['shipping_note'] ?? '',
                'review' => '',
                'ors_description' => $row['ors feed description'] ?? '',
                'segment' => $row['usiscs segment'] ?? '',
                'launch_tier' => $family['tier'] ?? '',
            ];
        }

        $this->group($priced);

        return ['rows' => array_values($priced), 'skipped' => $skipped];
    }

    /**
     * SKUs of the same family (2+ priced SKUs) become one product.
     *
     * @param array<string, array<string, string>> $rows
     */
    private function group(array &$rows): void
    {
        $byFamily = [];
        foreach ($rows as $sku => $row) {
            $byFamily[$row['name']][] = $sku;
        }

        $slugger = new AsciiSlugger();
        $used = [];
        foreach ($byFamily as $name => $skus) {
            if (count($skus) < 2) {
                continue;
            }

            $base = (string) $slugger->slug(str_replace(['®', '™', '©'], '', (string) $name))->lower();
            $group = $base;
            for ($i = 2; isset($used[$group]) || isset($rows[$group]); ++$i) {
                $group = $base.'-'.$i;
            }
            $used[$group] = true;
            $descriptions = [];
            $fallbacks = [];
            foreach ($skus as $sku) {
                $descriptions[$sku] = $rows[$sku]['ors_description'];
                $fallbacks[$sku] = '' !== $rows[$sku]['part_number'] ? $rows[$sku]['part_number'] : (string) $sku;
            }
            foreach (VariantNamer::names($descriptions, $fallbacks) as $sku => $variantName) {
                $rows[$sku]['group'] = $group;
                $rows[$sku]['variant_name'] = $variantName;
                $rows[$sku]['review'] = 'variant name drafted from the ORS description';
            }
        }
    }

    /**
     * @return array<string, array{family: string, brand: string, tier: string, direct_ship: string, shipping_note: string}>
     */
    private function families(string $path): array
    {
        $families = [];
        foreach (self::sheetRows($path, self::REVIEW_SHEET) as $row) {
            $sku = $row['ors sku'] ?? '';
            if ('' !== $sku && '' !== ($row['product family'] ?? '')) {
                $families[$sku] = [
                    'family' => $row['product family'],
                    'brand' => $row['brand'] ?? '',
                    'tier' => $row['launch tier'] ?? '',
                    'direct_ship' => $row['ors direct ship?'] ?? '',
                    'shipping_note' => self::shippingNote($row),
                ];
            }
        }

        return $families;
    }

    /**
     * Rows of one sheet as lower-case header => trimmed text.
     *
     * @return \Generator<int, array<string, string>>
     */
    private static function sheetRows(string $path, string $sheetName): \Generator
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('File not found: %s', $path));
        }

        $reader = new Reader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== $sheetName) {
                    continue;
                }
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(static fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '', $row->toArray());
                    if (null === $header) {
                        $header = array_map(static fn (string $cell): string => mb_strtolower($cell), $cells);
                        continue;
                    }
                    $values = [];
                    foreach ($header as $index => $column) {
                        $values[$column] = $cells[$index] ?? '';
                    }
                    yield $values;
                }

                return;
            }
        } finally {
            $reader->close();
        }

        throw new \RuntimeException(sprintf('%s has no "%s" sheet', basename($path), $sheetName));
    }

    /**
     * "Restricted-shipping review: Spray or compressed-gas product; check
     * carrier and hazmat rules", plus any ship method or fee notes ORS gave.
     *
     * @param array<string, string> $row
     */
    private static function shippingNote(array $row): string
    {
        $parts = [];
        $screen = $row['preliminary screen'] ?? '';
        if ('' !== $screen && 'standard-shipping candidate' !== strtolower($screen)) {
            $parts[] = trim($screen.': '.($row['reason for screen'] ?? ''), ': ');
        }
        foreach (['approved ship method' => 'Ship method', 'restriction / fee notes' => 'Notes'] as $column => $label) {
            if ('' !== ($row[$column] ?? '')) {
                $parts[] = $label.': '.$row[$column];
            }
        }

        return mb_substr(implode('. ', $parts), 0, 255);
    }

    /** The review sheets show ™ as ¢ (an encoding slip upstream). */
    private static function clean(string $text): string
    {
        return trim(str_replace('¢', '™', $text));
    }

    /** "MECHANIX WEAR®" -> "Mechanix Wear®" */
    private static function brand(string $text): string
    {
        return ucwords(mb_strtolower(self::clean($text)));
    }

    /** "70.058" -> "70.06", half up. */
    private static function cents(string $price): string
    {
        return number_format(round((float) $price + 1e-9, 2), 2, '.', '');
    }
}
