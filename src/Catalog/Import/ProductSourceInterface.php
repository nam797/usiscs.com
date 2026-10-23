<?php

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Where products come from: the go-live spreadsheet today, the supplier
 * feed later. A source only turns its format into rows of named columns;
 * validation and saving are the same for every source (ProductImporter).
 */
interface ProductSourceInterface
{
    /** Short name stored with each imported product, e.g. "excel" or "feed". */
    public function name(): string;

    /**
     * @return iterable<int, array<string, string>> row number (as the person
     *                                              fixing the source sees it) => column name => text
     *
     * @throws \RuntimeException when the source can't be read at all
     */
    public function rows(): iterable;
}
