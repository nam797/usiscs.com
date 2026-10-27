<?php

declare(strict_types=1);

namespace App\Distributor;

/**
 * What a line in a purchase order's timeline records: every state machine
 * transition (same value as PurchaseOrderTransition), plus a few things that
 * don't change the state.
 */
enum PurchaseOrderEventType: string
{
    case Created = 'created';
    case Start = 'start';
    case Submit = 'submit';
    case RequestManualEntry = 'request_manual_entry';
    case Requeue = 'requeue';
    case Flag = 'flag';
    case Retry = 'retry';
    case MarkSubmitted = 'mark_submitted';
    case Cancel = 'cancel';
    case Acknowledge = 'acknowledge';
    case Hold = 'hold';
    case Release = 'release';
    case ShipPartially = 'ship_partially';
    case Ship = 'ship';
    case Invoice = 'invoice';
    /** Staff changed the distributor or fulfilment, or left a note. */
    case Edited = 'edited';
    /** The distributor reported a new tracking number. */
    case Tracking = 'tracking';
    /** The distributor's back-ordered quantity changed. */
    case Backordered = 'backordered';
    /** The customer's shop shipment was updated (tracking, shipped email). */
    case CustomerUpdated = 'customer_updated';

    public static function of(PurchaseOrderTransition $transition): self
    {
        return self::from($transition->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Start => 'Sending',
            self::Submit => 'Sent to distributor',
            self::RequestManualEntry => 'Waiting for manual entry',
            self::Requeue => 'Attempt failed, will retry',
            self::Flag => 'Needs attention',
            self::Retry => 'Retried',
            self::MarkSubmitted => 'Marked as submitted',
            self::Cancel => 'Cancelled',
            self::Acknowledge => 'Acknowledged by distributor',
            self::Hold => 'On hold at distributor',
            self::Release => 'Released from hold',
            self::ShipPartially => 'Partially shipped',
            self::Ship => 'Shipped',
            self::Invoice => 'Invoiced',
            self::Edited => 'Edited',
            self::Tracking => 'Tracking number',
            self::Backordered => 'Back-order',
            self::CustomerUpdated => 'Customer updated',
        };
    }
}
