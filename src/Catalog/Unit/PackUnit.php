<?php

declare(strict_types=1);

namespace App\Catalog\Unit;

/**
 * How a SKU is sold: the selling unit (a case) and, when it holds more than
 * one of something, how many of what (144 pairs). ORS writes this as
 * "144 PR = 1 CA"; customers see "case of 144 pairs".
 */
final class PackUnit
{
    /** Unit codes seen in ORS data => [singular, plural]. */
    public const UNITS = [
        'EA' => ['each', 'each'],
        'PR' => ['pair', 'pairs'],
        'BX' => ['box', 'boxes'],
        'BOX' => ['box', 'boxes'],
        'CA' => ['case', 'cases'],
        'CS' => ['case', 'cases'],
        'CT' => ['carton', 'cartons'],
        'CTN' => ['carton', 'cartons'],
        'DZ' => ['dozen', 'dozen'],
        'DOZ' => ['dozen', 'dozen'],
        'GS' => ['gross', 'gross'],
        'PK' => ['pack', 'packs'],
        'BG' => ['bag', 'bags'],
        'BDL' => ['bundle', 'bundles'],
        'BA' => ['bale', 'bales'],
        'BAL' => ['bale', 'bales'],
        'RL' => ['roll', 'rolls'],
        'ROL' => ['roll', 'rolls'],
        'CN' => ['can', 'cans'],
        'CAN' => ['can', 'cans'],
        'BO' => ['bottle', 'bottles'],
        'TB' => ['tube', 'tubes'],
        'CQ' => ['cartridge', 'cartridges'],
        'ST' => ['set', 'sets'],
        'SET' => ['set', 'sets'],
        'KT' => ['kit', 'kits'],
        'KIT' => ['kit', 'kits'],
        'PA' => ['pail', 'pails'],
        'DR' => ['drum', 'drums'],
        'GA' => ['gallon', 'gallons'],
        'TC' => ['tote', 'totes'],
        'SO' => ['spool', 'spools'],
        'LB' => ['pound', 'pounds'],
        'FT' => ['foot', 'feet'],
        'YD' => ['yard', 'yards'],
        'SH' => ['sheet', 'sheets'],
        'SHE' => ['sheet', 'sheets'],
        'DC' => ['disc', 'discs'],
        'DI' => ['dispenser', 'dispensers'],
        'CD' => ['card', 'cards'],
        'CG' => ['card', 'cards'],
    ];

    /** Units that are themselves a count. */
    private const COUNTS = ['DZ' => 12, 'DOZ' => 12, 'GS' => 144];

    private function __construct(
        /** the unit a price is for, e.g. "CA" */
        public readonly string $sellUnit,
        /** how many inner units one selling unit holds; 1 when it's just the one thing */
        public readonly int $quantity,
        /** e.g. "PR"; same as $sellUnit when $quantity is 1 */
        public readonly string $innerUnit,
    ) {
    }

    public static function of(string $sellUnit, int $quantity = 1, ?string $innerUnit = null): self
    {
        $sellUnit = strtoupper($sellUnit);
        $innerUnit = strtoupper($innerUnit ?? $sellUnit);
        if ($quantity < 1) {
            throw new \InvalidArgumentException('A pack holds at least 1.');
        }
        if (1 === $quantity && $innerUnit !== $sellUnit && 'EA' === $innerUnit) {
            // "1 EA = 1 BX": a box is sold as one thing.
            $innerUnit = $sellUnit;
        }

        return new self($sellUnit, $quantity, $innerUnit);
    }

    /**
     * Reads what people and ORS write:
     * "144 PR = 1 CA", "100 EA/PK", "CA (12 EA = 1 CA)", "CA", "each", "box of 50", "case of 144 pairs".
     *
     * @throws \InvalidArgumentException when it can't be read
     */
    public static function parse(string $text): self
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        // "CA (12 EA = 1 CA)": the part in brackets says it all.
        if (preg_match('/^(\w+) \((.+)\)$/', $text, $m)) {
            $inner = self::parse($m[2]);
            if ($inner->sellUnit !== strtoupper($m[1])) {
                throw new \InvalidArgumentException(sprintf('"%s" names two different selling units', $text));
            }

            return $inner;
        }
        if (preg_match('/^(\d+) ?(\w+) ?= ?1 ?(\w+)$/', $text, $m)) {
            return self::of(self::code($m[3]), (int) $m[1], self::code($m[2]));
        }
        if (preg_match('/^(\d+) ?(\w+) ?\/ ?(\w+)$/', $text, $m)) {
            return self::of(self::code($m[3]), (int) $m[1], self::code($m[2]));
        }
        if (preg_match('/^(\w+) of (\d+)(?: (\w+))?$/i', $text, $m)) {
            return self::of(self::code($m[1]), (int) $m[2], isset($m[3]) ? self::code($m[3]) : 'EA');
        }
        if (preg_match('/^\w+$/', $text)) {
            return self::of(self::code($text));
        }

        throw new \InvalidArgumentException(sprintf('"%s" is not a unit of measure (e.g. each, case of 12, 144 PR = 1 CA)', $text));
    }

    /** What a price is for, e.g. "each", "box", "case of 12", "case of 144 pairs". */
    public function label(): string
    {
        $sell = self::words($this->sellUnit)[0];
        if (1 === $this->quantity) {
            return $sell;
        }
        // "dozen pairs", not "dozen of 12 pairs".
        if ((self::COUNTS[$this->sellUnit] ?? null) === $this->quantity) {
            return 'EA' === $this->innerUnit ? $sell : sprintf('%s %s', $sell, self::words($this->innerUnit)[1]);
        }
        if ('EA' === $this->innerUnit) {
            return sprintf('%s of %d', $sell, $this->quantity);
        }

        return sprintf('%s of %d %s', $sell, $this->quantity, self::words($this->innerUnit)[1]);
    }

    /** Shown after a price: "each", "per case of 12". */
    public function perPrice(): string
    {
        $label = $this->label();

        return 'each' === $label ? $label : 'per '.$label;
    }

    /** Stored form, as ORS writes it: "144 PR = 1 CA". */
    public function toString(): string
    {
        return sprintf('%d %s = 1 %s', $this->quantity, $this->innerUnit, $this->sellUnit);
    }

    /** A unit code ("CA") from a code or an English word ("case", "cases"). */
    private static function code(string $word): string
    {
        // English words first, so "box" is BX rather than ORS's other code BOX.
        foreach (self::UNITS as $code => [$singular, $plural]) {
            if (strtolower($word) === $singular || strtolower($word) === $plural) {
                return $code;
            }
        }
        $upper = strtoupper($word);
        if (isset(self::UNITS[$upper])) {
            return $upper;
        }
        if (preg_match('/^[A-Z]{1,5}$/', $word)) {
            // An ORS code we have no word for yet still works; it's shown as is.
            return $upper;
        }

        throw new \InvalidArgumentException(sprintf('"%s" is not a unit of measure', $word));
    }

    /** @return array{string, string} */
    private static function words(string $code): array
    {
        return self::UNITS[$code] ?? [$code, $code];
    }
}
