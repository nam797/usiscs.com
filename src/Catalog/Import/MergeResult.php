<?php

declare(strict_types=1);

namespace App\Catalog\Import;

final class MergeResult
{
    /**
     * @param array<string, scalar|null> $toWrite  fields to update on the product
     * @param list<string>               $kept     fields left alone because an admin changed them
     * @param array<string, scalar|null> $snapshot what to remember as "last imported"
     */
    public function __construct(
        public readonly array $toWrite,
        public readonly array $kept,
        public readonly array $snapshot,
    ) {
    }
}
