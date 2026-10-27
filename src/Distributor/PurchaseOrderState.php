<?php

declare(strict_types=1);

namespace App\Distributor;

/**
 * Where a purchase order is, as places of the "purchase_order" state machine
 * (config/packages/workflow.yaml). Strings, not an enum, because that's what
 * the workflow marking store reads and writes.
 */
final class PurchaseOrderState
{
    /** Waiting for the worker to send it (or to retry after a failed attempt). */
    public const QUEUED = 'queued';

    /** The worker is sending it right now. */
    public const SUBMITTING = 'submitting';

    /** The distributor has no order API (or it's switched off): staff enter it by hand. */
    public const AWAITING_MANUAL_ENTRY = 'awaiting_manual_entry';

    public const SUBMITTED = 'submitted';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ON_HOLD = 'on_hold';

    public const PARTIALLY_SHIPPED = 'partially_shipped';

    public const SHIPPED = 'shipped';

    public const INVOICED = 'invoiced';

    /** Rejected, out of retries, or missing a distributor/fulfilment: staff must look. */
    public const NEEDS_ATTENTION = 'needs_attention';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::QUEUED => 'Queued',
        self::SUBMITTING => 'Sending',
        self::AWAITING_MANUAL_ENTRY => 'Awaiting manual entry',
        self::SUBMITTED => 'Submitted',
        self::ACKNOWLEDGED => 'Acknowledged',
        self::ON_HOLD => 'On hold',
        self::PARTIALLY_SHIPPED => 'Partially shipped',
        self::SHIPPED => 'Shipped',
        self::INVOICED => 'Invoiced',
        self::NEEDS_ATTENTION => 'Needs attention',
        self::CANCELLED => 'Cancelled',
    ];

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }
}
