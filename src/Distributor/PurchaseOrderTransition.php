<?php

declare(strict_types=1);

namespace App\Distributor;

/**
 * Transitions of the "purchase_order" state machine
 * (config/packages/workflow.yaml). Each one is also the type of the
 * PurchaseOrderEvent it logs, so the values must match the workflow config.
 */
enum PurchaseOrderTransition: string
{
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
}
