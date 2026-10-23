<?php

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * A spreadsheet/feed row that can't be imported. Carries every problem
 * found in the row, so the person fixing the sheet sees them all at once.
 */
final class InvalidProductRow extends \RuntimeException
{
    /** @param non-empty-list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode('; ', $problems));
    }
}
