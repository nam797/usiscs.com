<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Unit;

use App\Catalog\Unit\PackUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackUnitTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function readable(): iterable
    {
        yield 'ORS price feed' => ['144 PR = 1 CA', '144 PR = 1 CA', 'case of 144 pairs'];
        yield 'ORS price service' => ['100 EA/PK', '100 EA = 1 PK', 'pack of 100'];
        yield 'code with conversion' => ['CA (12 EA = 1 CA)', '12 EA = 1 CA', 'case of 12'];
        yield 'single item' => ['1 EA = 1 EA', '1 EA = 1 EA', 'each'];
        yield 'box sold as one thing' => ['1 EA = 1 BX', '1 BX = 1 BX', 'box'];
        yield 'code alone' => ['DZ', '1 DZ = 1 DZ', 'dozen'];
        yield 'plain word' => ['each', '1 EA = 1 EA', 'each'];
        yield 'words with a count' => ['box of 50', '50 EA = 1 BX', 'box of 50'];
        yield 'words with a count and unit' => ['case of 144 pairs', '144 PR = 1 CA', 'case of 144 pairs'];
        yield 'extra spaces and lower case' => ['  10 cq =  1 bx ', '10 CQ = 1 BX', 'box of 10 cartridges'];
        yield 'dozen of pairs' => ['12 PR = 1 DZ', '12 PR = 1 DZ', 'dozen pairs'];
        yield 'gross' => ['144 EA = 1 GS', '144 EA = 1 GS', 'gross'];
        yield 'odd dozen' => ['6 PR = 1 DZ', '6 PR = 1 DZ', 'dozen of 6 pairs'];
        yield 'code we have no word for' => ['4 EA = 1 XQ', '4 EA = 1 XQ', 'XQ of 4'];
    }

    #[DataProvider('readable')]
    public function testItReadsWhatOrsAndPeopleWrite(string $text, string $stored, string $label): void
    {
        $unit = PackUnit::parse($text);

        self::assertSame($stored, $unit->toString());
        self::assertSame($label, $unit->label());
    }

    public function testAPriceIsEachOrPerPack(): void
    {
        self::assertSame('each', PackUnit::parse('EA')->perPrice());
        self::assertSame('per case of 12', PackUnit::parse('12 EA = 1 CA')->perPrice());
    }

    public function testTheStoredFormReadsBackTheSame(): void
    {
        $unit = PackUnit::parse('case of 144 pairs');

        self::assertEquals($unit, PackUnit::parse($unit->toString()));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'sentence' => ['sold by the case'];
        yield 'unknown word' => ['bushel'];
        yield 'two selling units' => ['CA (12 EA = 1 BX)'];
        yield 'zero' => ['0 EA = 1 CA'];
        yield 'fraction' => ['1.5 LB = 1 BG'];
    }

    #[DataProvider('unreadable')]
    public function testItRejectsWhatItCantRead(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PackUnit::parse($text);
    }
}
