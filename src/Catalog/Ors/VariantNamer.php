<?php

declare(strict_types=1);

namespace App\Catalog\Ors;

/**
 * First-draft variant names for SKUs sold as one product, from ORS's short
 * descriptions: the words every SKU in the group shares are dropped, the
 * rest is what tells them apart ("HYFLEX 11-600 BLK ... SZ 10" -> "Black,
 * Size 10"). ORS abbreviations are expanded where they're unambiguous.
 * Meant to be reviewed: the converter marks every name it made up.
 */
final class VariantNamer
{
    private const WORDS = [
        'SZ' => 'Size', 'SIZE' => 'Size',
        'BLK' => 'Black', 'BK' => 'Black', 'BLACK' => 'Black',
        'WHT' => 'White', 'WH' => 'White', 'WHITE' => 'White',
        'GRN' => 'Green', 'GREEN' => 'Green', 'BLU' => 'Blue', 'BLUE' => 'Blue',
        'RED' => 'Red', 'YEL' => 'Yellow', 'YLW' => 'Yellow', 'YELLOW' => 'Yellow',
        'ORG' => 'Orange', 'ORANGE' => 'Orange', 'GRY' => 'Gray', 'GRAY' => 'Gray', 'GREY' => 'Gray',
        'BRN' => 'Brown', 'BROWN' => 'Brown', 'CLR' => 'Clear', 'CLEAR' => 'Clear',
        'SM' => 'Small', 'SML' => 'Small', 'SMALL' => 'Small', 'MED' => 'Medium', 'MEDIUM' => 'Medium',
        'LG' => 'Large', 'LRG' => 'Large', 'LARGE' => 'Large',
        'XL' => 'XL', 'XXL' => '2XL', '2XL' => '2XL', 'XXXL' => '3XL', '3XL' => '3XL', '4XL' => '4XL', '5XL' => '5XL',
        'S' => 'S', 'M' => 'M', 'L' => 'L',
    ];

    /**
     * @param array<string, string> $descriptions SKU => ORS description, for every SKU in one group
     * @param array<string, string> $fallbacks    SKU => what to use when nothing tells it apart (e.g. the part number)
     *
     * @return array<string, string> SKU => variant name, unique within the group
     */
    public static function names(array $descriptions, array $fallbacks = []): array
    {
        $tokens = [];
        foreach ($descriptions as $sku => $description) {
            $tokens[$sku] = self::tokens($description);
        }

        $shared = null;
        foreach (array_filter($tokens) as $list) {
            $shared = null === $shared ? array_unique($list) : array_intersect($shared, $list);
        }
        $shared = array_flip($shared ?? []);

        $names = [];
        foreach ($tokens as $sku => $list) {
            $own = array_values(array_filter($list, static fn (string $t): bool => !isset($shared[$t])));
            $name = self::words($own);
            $names[(string) $sku] = '' !== $name ? $name : ($fallbacks[$sku] ?? (string) $sku);
        }

        // Two SKUs with the same name would be impossible to choose between.
        $counts = array_count_values($names);
        foreach ($names as $sku => $name) {
            if ($counts[$name] > 1) {
                $names[$sku] = sprintf('%s (%s)', $name, $fallbacks[$sku] ?? $sku);
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function tokens(string $description): array
    {
        $description = strtoupper(trim(preg_replace('/\s+/', ' ', $description) ?? ''));
        // "SZ10" and "SZ 10" are one token, so a shared "SZ" isn't dropped on its own.
        $description = (string) preg_replace('/\b(?:SZ|SIZE) ?(?=\w)/', 'SZ_', $description);

        return '' === $description ? [] : explode(' ', $description);
    }

    /** @param list<string> $tokens */
    private static function words(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = str_starts_with($token, 'SZ_') ? 'Size '.self::word(substr($token, 3)) : self::word($token);
        }

        // Colours and sizes read best as separate items: "Black, Size 10".
        $text = implode(' ', $parts);

        return (string) preg_replace('/^(Black|White|Green|Blue|Red|Yellow|Orange|Gray|Brown|Clear) (Size .+)$/', '$1, $2', $text);
    }

    private static function word(string $token): string
    {
        if (isset(self::WORDS[$token])) {
            return self::WORDS[$token];
        }

        // Codes and measurements (anything with a digit or symbol) stay as ORS wrote them.
        if (preg_match('/^[A-Z]+(?:-[A-Z]+)*$/', $token)) {
            return ucwords(strtolower($token), '-');
        }

        return $token;
    }
}
