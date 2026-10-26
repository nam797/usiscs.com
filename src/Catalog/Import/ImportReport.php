<?php

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * What an import did, row by row, for the command output (and later the
 * backoffice upload page).
 */
final class ImportReport
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public int $created = 0;
    public int $createdLive = 0;
    /** SKUs added as a new choice (size, colour) to a product that already existed */
    public int $added = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $untouched = 0;
    public int $failed = 0;

    /** @var list<array{row: int, sku: ?string, level: string, message: string}> */
    private array $messages = [];

    public function __construct(public readonly bool $dryRun)
    {
    }

    public function add(int $row, ?string $sku, string $level, string $message): void
    {
        $this->messages[] = ['row' => $row, 'sku' => $sku, 'level' => $level, 'message' => $message];
    }

    /** @return list<array{row: int, sku: ?string, level: string, message: string}> */
    public function messages(?string $level = null): array
    {
        if (null === $level) {
            return $this->messages;
        }

        return array_values(array_filter($this->messages, static fn (array $m): bool => $m['level'] === $level));
    }

    public function hasErrors(): bool
    {
        return $this->failed > 0;
    }
}
