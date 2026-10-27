<?php

declare(strict_types=1);

namespace App\Distributor\OrsNasco;

use App\Distributor\DistributorOrderStatus;
use App\Distributor\PurchaseOrderState;
use App\Supplier\OrsNasco\OrderSuffix;

/**
 * Turns the suffixes of an ORS order into one status. ORS splits an order
 * as it ships parts from different warehouses or ships back-orders later;
 * a cancelled suffix is usually just the part ORS moved to a new suffix
 * (their example: -00 cancelled, -01 shipped).
 *
 *   all suffixes cancelled                     cancelled
 *   everything shipped, every suffix invoiced  invoiced
 *   everything shipped                         shipped
 *   any suffix on hold                         on hold
 *   something shipped                          partially shipped
 *   otherwise (entered, picking)               acknowledged
 *
 * "Everything shipped" is by quantity per item, so a back-order keeps it
 * partially shipped.
 */
final class OrsOrderStatusMapper
{
    /** @param list<OrderSuffix> $suffixes all of one order, as the Order Query service returns them */
    public static function map(array $suffixes): ?DistributorOrderStatus
    {
        if ([] === $suffixes) {
            return null;
        }

        usort($suffixes, static fn (OrderSuffix $a, OrderSuffix $b): int => strnatcmp($a->orderNumber, $b->orderNumber));
        $reference = $suffixes[0]->baseOrderNumber();
        $live = array_values(array_filter($suffixes, static fn (OrderSuffix $s): bool => !$s->isCancelled()));
        $details = ['suffixes' => array_map(static fn (OrderSuffix $s): array => [
            'order' => $s->orderNumber,
            'stage' => $s->stage,
            'held' => $s->held,
            'warehouse' => $s->warehouse,
        ], $suffixes)];

        if ([] === $live) {
            return new DistributorOrderStatus($reference, PurchaseOrderState::CANCELLED, sprintf('ORS order %s was cancelled.', $reference), details: $details);
        }

        /** @var array<string, array{ordered: int, shipped: int, backordered: int}> $items */
        $items = [];
        $shipments = [];
        $invoice = [];
        foreach ($live as $suffix) {
            foreach ($suffix->lines as $line) {
                $items[$line->item] ??= ['ordered' => 0, 'shipped' => 0, 'backordered' => 0];
                $items[$line->item]['ordered'] = max($items[$line->item]['ordered'], $line->quantityOrdered);
                if ($suffix->stage >= OrderSuffix::STAGE_SHIPPED && OrderSuffix::STAGE_HELD_OR_CANCELLED !== $suffix->stage) {
                    $items[$line->item]['shipped'] += $line->quantityShipped;
                }
                // The latest suffix holding the item has the current back-order.
                $items[$line->item]['backordered'] = $line->quantityBackordered;
            }
            foreach ($suffix->shipments as $shipment) {
                $shipments[$shipment['trackingNumber']] = [
                    'trackingNumber' => $shipment['trackingNumber'],
                    'carrier' => '' !== $shipment['shipViaDescription'] ? $shipment['shipViaDescription'] : $shipment['shipVia'],
                ];
            }
            foreach ($suffix->invoiceValuesInCents as $type => $cents) {
                $invoice[$type] = ($invoice[$type] ?? 0) + $cents;
            }
        }

        $allShipped = [] !== $items && [] === array_filter($items, static fn (array $i): bool => $i['shipped'] < $i['ordered'] || $i['backordered'] > 0);
        $anyShipped = [] !== array_filter($items, static fn (array $i): bool => $i['shipped'] > 0);
        $allInvoiced = [] === array_filter($live, static fn (OrderSuffix $s): bool => OrderSuffix::STAGE_INVOICED !== $s->stage);
        $anyHeld = [] !== array_filter($live, static fn (OrderSuffix $s): bool => $s->held || OrderSuffix::STAGE_HELD_OR_CANCELLED === $s->stage);

        $state = match (true) {
            $allShipped && $allInvoiced => PurchaseOrderState::INVOICED,
            $allShipped => PurchaseOrderState::SHIPPED,
            $anyHeld => PurchaseOrderState::ON_HOLD,
            $anyShipped => PurchaseOrderState::PARTIALLY_SHIPPED,
            default => PurchaseOrderState::ACKNOWLEDGED,
        };

        return new DistributorOrderStatus(
            $reference,
            $state,
            sprintf('ORS order %s: %s (%d part%s).', $reference, strtolower(PurchaseOrderState::label($state)), count($live), 1 === count($live) ? '' : 's'),
            array_map(static fn (array $i): array => ['shipped' => $i['shipped'], 'backordered' => $i['backordered']], $items),
            array_values($shipments),
            $allInvoiced ? $invoice : [],
            $details,
        );
    }
}
