<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Import;

use App\Catalog\Import\FieldMerger;
use PHPUnit\Framework\TestCase;

final class FieldMergerTest extends TestCase
{
    public function testAFieldNobodyEditedTakesTheNewValue(): void
    {
        $result = (new FieldMerger())->merge(
            snapshot: ['price:default' => 1299],
            current: ['price:default' => 1299],
            incoming: ['price:default' => 1399],
        );

        self::assertSame(['price:default' => 1399], $result->toWrite);
        self::assertSame([], $result->kept);
        self::assertSame(['price:default' => 1399], $result->snapshot);
    }

    public function testAFieldAnAdminEditedIsKept(): void
    {
        $result = (new FieldMerger())->merge(
            snapshot: ['name' => 'Hex bolt'],
            current: ['name' => 'Hex Bolt, Grade 8'],
            incoming: ['name' => 'HEX BOLT GR8'],
        );

        self::assertSame([], $result->toWrite);
        self::assertSame(['name'], $result->kept);
        self::assertSame(['name' => 'Hex bolt'], $result->snapshot, 'The snapshot keeps the last imported value.');
    }

    public function testAnAdminEditStaysProtectedOnLaterImportsToo(): void
    {
        $merger = new FieldMerger();
        $first = $merger->merge(['name' => 'Hex bolt'], ['name' => 'Admin name'], ['name' => 'Feed v2']);
        $second = $merger->merge($first->snapshot, ['name' => 'Admin name'], ['name' => 'Feed v3']);

        self::assertSame(['name'], $second->kept);
        self::assertSame([], $second->toWrite);
    }

    public function testWhenTheFeedCatchesUpWithTheAdminTheyConverge(): void
    {
        $result = (new FieldMerger())->merge(
            snapshot: ['name' => 'Hex bolt'],
            current: ['name' => 'Hex Bolt, Grade 8'],
            incoming: ['name' => 'Hex Bolt, Grade 8'],
        );

        self::assertSame([], $result->toWrite);
        self::assertSame([], $result->kept);
        self::assertSame(['name' => 'Hex Bolt, Grade 8'], $result->snapshot, 'Future feed changes flow again.');
    }

    public function testAnUnchangedValueIsNeitherWrittenNorReportedAsKept(): void
    {
        $result = (new FieldMerger())->merge(['stock' => 5], ['stock' => 5], ['stock' => 5]);

        self::assertSame([], $result->toWrite);
        self::assertSame([], $result->kept);
    }

    public function testBlankAndNullCountAsTheSameNoValue(): void
    {
        $result = (new FieldMerger())->merge(
            snapshot: ['description' => null],
            current: ['description' => ''],
            incoming: ['description' => 'New text'],
        );

        self::assertSame(['description' => 'New text'], $result->toWrite);
    }

    public function testAFieldTheImportNeverWroteIsFilledOnlyWhileEmpty(): void
    {
        $merger = new FieldMerger();

        $empty = $merger->merge([], ['manufacturer' => null], ['manufacturer' => 'Acme']);
        self::assertSame(['manufacturer' => 'Acme'], $empty->toWrite);

        $filledByAdmin = $merger->merge([], ['manufacturer' => 'Hand-entered'], ['manufacturer' => 'Acme']);
        self::assertSame([], $filledByAdmin->toWrite);
        self::assertSame(['manufacturer'], $filledByAdmin->kept);
    }

    public function testFieldsAreDecidedIndependently(): void
    {
        $result = (new FieldMerger())->merge(
            snapshot: ['name' => 'Hex bolt', 'price:default' => 1299, 'stock' => 10],
            current: ['name' => 'Renamed by admin', 'price:default' => 1299, 'stock' => 10],
            incoming: ['name' => 'Feed name', 'price:default' => 1399, 'stock' => 8],
        );

        self::assertSame(['price:default' => 1399, 'stock' => 8], $result->toWrite);
        self::assertSame(['name'], $result->kept);
    }

    public function testFieldsNotInThisImportAreLeftInTheSnapshot(): void
    {
        $result = (new FieldMerger())->merge(
            snapshot: ['name' => 'Hex bolt', 'manufacturer' => 'Acme'],
            current: ['name' => 'Hex bolt', 'manufacturer' => 'Acme'],
            incoming: ['name' => 'Hex bolt v2'],
        );

        self::assertSame(['name' => 'Hex bolt v2', 'manufacturer' => 'Acme'], $result->snapshot);
    }
}
