<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Ors;

use App\Catalog\Ors\VariantNamer;
use PHPUnit\Framework\TestCase;

final class VariantNamerTest extends TestCase
{
    public function testWhatTheSkusShareIsDroppedAndAbbreviationsAreSpelledOut(): void
    {
        $names = VariantNamer::names([
            '012-11-600-10-BK' => 'HYFLEX 11-600 BLK LT WGHT ASSEMB PU/COAT SZ 10',
            '012-11-600-9-BK' => 'HYFLEX 11-600 BLK LT WGHT ASSEMB PU/COAT SZ 9',
            '012-11-600-6-WH' => 'HYFLEX 11-600 WHT LT WGHT ASSEMB PU/COAT SZ6',
        ]);

        self::assertSame([
            '012-11-600-10-BK' => 'Black, Size 10',
            '012-11-600-9-BK' => 'Black, Size 9',
            '012-11-600-6-WH' => 'White, Size 6',
        ], $names);
    }

    public function testWordsAreTitleCasedAndCodesKeptAsTheyAre(): void
    {
        $names = VariantNamer::names([
            '690-159060099' => '20OZ YIELD QWIK STIK ZERO  STRAWLEM  500/CASE',
            '690-159060101' => '20OZ YIELD QWIK STIK ZERO  MIXED BERRY  500/CASE',
            '632-31695' => '20OZ YIELD QWIK STIK ZERO D342 500/CASE',
        ]);

        self::assertSame(['690-159060099' => 'Strawlem', '690-159060101' => 'Mixed Berry', '632-31695' => 'D342'], $names);
    }

    public function testSkusThatCantBeToldApartFallBackAndStayUnique(): void
    {
        $names = VariantNamer::names(
            ['A-1' => 'CUT-OFF WHEEL', 'A-2' => 'CUT-OFF WHEEL', 'A-3' => ''],
            ['A-1' => '90036', 'A-2' => '90037'],
        );

        self::assertSame(['A-1' => '90036', 'A-2' => '90037', 'A-3' => 'A-3'], $names);
    }

    public function testSizesSurviveWhenEverySkuHasOne(): void
    {
        $names = VariantNamer::names(
            ['A-1' => 'GLOVE SZ 9 PK', 'A-2' => 'GLOVE SZ 9 CA', 'A-3' => 'GLOVE SZ 10 CA'],
            ['A-1' => 'P1', 'A-2' => 'P2'],
        );

        self::assertSame(['A-1' => 'Size 9 Pk', 'A-2' => 'Size 9 Ca', 'A-3' => 'Size 10 Ca'], $names);

        $same = VariantNamer::names(['A-1' => 'GLOVE SZ 9', 'A-2' => 'GLOVE SZ 9 '], ['A-1' => 'P1', 'A-2' => 'P2']);
        self::assertSame(['A-1' => 'P1', 'A-2' => 'P2'], $same);
    }
}
