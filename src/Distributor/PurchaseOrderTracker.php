<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;

/**
 * Asks the distributor where a purchase order is and catches ours up:
 * tracking numbers, back-orders and shipped quantities per line, and the
 * state (acknowledged, on hold, shipped, invoiced...), each on the
 * timeline. Run for every open purchase order by app:distributor:sync-orders.
 *
 * Also finds orders staff placed by hand but never marked submitted, by
 * our PO number. A distributor cancelling an order we sent puts it in
 * "needs attention": the customer has paid for it.
 *
 * Changes the purchase order only; the caller flushes.
 */
final class PurchaseOrderTracker
{
    /** States worth asking the distributor about. */
    public const TRACKED_STATES = [
        PurchaseOrderState::AWAITING_MANUAL_ENTRY,
        PurchaseOrderState::NEEDS_ATTENTION,
        PurchaseOrderState::SUBMITTED,
        PurchaseOrderState::ACKNOWLEDGED,
        PurchaseOrderState::ON_HOLD,
        PurchaseOrderState::PARTIALLY_SHIPPED,
        PurchaseOrderState::SHIPPED,
    ];

    /**
     * Transitions that move towards each reported state, tried in order.
     * Each step picks the first one the state machine allows from where the
     * purchase order is now, so e.g. on hold -> invoiced goes release, ship,
     * invoice.
     */
    private const PATHS = [
        PurchaseOrderState::ACKNOWLEDGED => [PurchaseOrderTransition::Acknowledge, PurchaseOrderTransition::Release],
        PurchaseOrderState::ON_HOLD => [PurchaseOrderTransition::Hold],
        PurchaseOrderState::PARTIALLY_SHIPPED => [PurchaseOrderTransition::Release, PurchaseOrderTransition::ShipPartially],
        PurchaseOrderState::SHIPPED => [PurchaseOrderTransition::Release, PurchaseOrderTransition::Ship],
        PurchaseOrderState::INVOICED => [PurchaseOrderTransition::Release, PurchaseOrderTransition::Ship, PurchaseOrderTransition::Invoice],
    ];

    public function __construct(
        private readonly DistributorRegistry $distributors,
        private readonly PurchaseOrderStateMachine $stateMachine,
        private readonly PurchaseOrderNotifierInterface $notifier,
    ) {
    }

    /**
     * @return bool whether anything changed
     *
     * @throws DistributorUnavailableException
     */
    public function track(PurchaseOrder $purchaseOrder): bool
    {
        $distributor = $this->distributors->get($purchaseOrder->getDistributor());
        if (null === $distributor || !in_array($purchaseOrder->getState(), self::TRACKED_STATES, true)) {
            return false;
        }

        $status = $distributor->fetchStatus($purchaseOrder);
        $purchaseOrder->markChecked();
        if (null === $status) {
            return false;
        }

        $changed = $this->recordSubmission($purchaseOrder, $status, $distributor->getName());
        $changed = $this->recordLines($purchaseOrder, $status) || $changed;
        $changed = $this->recordShipments($purchaseOrder, $status) || $changed;

        return $this->moveTo($purchaseOrder, $status, $distributor->getName()) || $changed;
    }

    /** Placed by hand (or after needing attention) without being marked: it's submitted now. */
    private function recordSubmission(PurchaseOrder $purchaseOrder, DistributorOrderStatus $status, string $distributorName): bool
    {
        if (PurchaseOrderState::CANCELLED === $status->state) {
            return false;
        }

        if (in_array($purchaseOrder->getState(), [PurchaseOrderState::AWAITING_MANUAL_ENTRY, PurchaseOrderState::NEEDS_ATTENTION], true)
            && $this->stateMachine->can($purchaseOrder, PurchaseOrderTransition::MarkSubmitted)) {
            $purchaseOrder->markSubmitted($status->reference);
            $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::MarkSubmitted, sprintf(
                'Found at %s as order %s, placed with our PO number.',
                $distributorName,
                $status->reference,
            ));

            return true;
        }

        if (null === $purchaseOrder->getReference()) {
            $purchaseOrder->setReference($status->reference);

            return true;
        }

        return false;
    }

    private function recordLines(PurchaseOrder $purchaseOrder, DistributorOrderStatus $status): bool
    {
        $changed = false;
        foreach ($status->lines as $supplierSku => $progress) {
            $line = $purchaseOrder->getLineForSupplierSku((string) $supplierSku);
            if (null === $line) {
                continue;
            }

            $before = $line->getQuantityBackordered();
            if ($line->recordProgress($progress['shipped'], $progress['backordered'])) {
                $purchaseOrder->log(PurchaseOrderEventType::Backordered, 0 === $progress['backordered']
                    ? sprintf('%s: no longer on back-order.', $line->getVariantCode())
                    : sprintf('%s: %d on back-order (was %d).', $line->getVariantCode(), $progress['backordered'], $before));
                $changed = true;
            }
        }

        return $changed;
    }

    private function recordShipments(PurchaseOrder $purchaseOrder, DistributorOrderStatus $status): bool
    {
        $changed = false;
        foreach ($status->shipments as $shipment) {
            if ($purchaseOrder->addShipment($shipment['trackingNumber'], $shipment['carrier'])) {
                $purchaseOrder->log(PurchaseOrderEventType::Tracking, trim($shipment['carrier'].' '.$shipment['trackingNumber']));
                $changed = true;
            }
        }

        return $changed;
    }

    private function moveTo(PurchaseOrder $purchaseOrder, DistributorOrderStatus $status, string $distributorName): bool
    {
        if (PurchaseOrderState::CANCELLED === $status->state) {
            if (!$this->stateMachine->can($purchaseOrder, PurchaseOrderTransition::Flag)) {
                return false;
            }
            $reason = sprintf('%s cancelled order %s. The customer has paid: re-order or refund.', $distributorName, $status->reference);
            $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Flag, $reason, payload: $status->details);
            $this->notifier->needsAttention($purchaseOrder, $reason);

            return true;
        }

        $moved = false;
        $path = self::PATHS[$status->state] ?? [];
        // At most one step per transition in the path: never loops.
        for ($step = 0; $step < count($path) && $purchaseOrder->getState() !== $status->state; ++$step) {
            $next = null;
            foreach ($path as $transition) {
                // Partially shipped -> partially shipped is allowed by the graph but isn't a move.
                if (PurchaseOrderTransition::ShipPartially === $transition && PurchaseOrderState::PARTIALLY_SHIPPED === $purchaseOrder->getState()) {
                    continue;
                }
                if ($this->stateMachine->can($purchaseOrder, $transition)) {
                    $next = $transition;
                    break;
                }
            }
            if (null === $next) {
                break;
            }

            $payload = $status->details;
            if (PurchaseOrderTransition::Invoice === $next) {
                $payload['invoice_in_cents'] = $status->invoiceInCents;
            }
            $this->stateMachine->apply($purchaseOrder, $next, $status->summary, payload: $payload);
            $moved = true;
        }

        return $moved;
    }
}
