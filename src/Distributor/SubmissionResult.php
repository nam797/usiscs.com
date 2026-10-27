<?php

declare(strict_types=1);

namespace App\Distributor;

/** A distributor accepted an order. */
final class SubmissionResult
{
    /** @param array<string, mixed> $details logged with the event; never credentials */
    public function __construct(
        /** the distributor's order number, when it gives one straight away */
        public readonly ?string $reference,
        public readonly ?string $message = null,
        public readonly array $details = [],
    ) {
    }
}
