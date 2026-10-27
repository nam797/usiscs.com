<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderShipment;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\ShipmentEmailManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Passes shipping on to the customer's order:
 *
 *  - tracking numbers go on the order's shipment (customers see them in
 *    their account): the distributor's for purchase orders it ships
 *    straight to the customer, ours for cross-dock ones we shipped on from
 *    the warehouse. A cross-dock purchase order's distributor tracking is
 *    the parcel to our warehouse and is never shown to the customer.
 *  - once every purchase order of the order has reached the customer's
 *    carrier (direct ones shipped by the distributor, cross-dock ones
 *    shipped on by us), the shipment is marked shipped and Sylius's "your
 *    order has shipped" email goes out, as if staff had clicked Ship in the
 *    admin.
 *
 * Run by app:distributor:sync-orders and by the warehouse's "Ship to
 * customer" action.
 */
final class CustomerShipmentUpdater
{
    private const SHIPPED = [PurchaseOrderState::SHIPPED, PurchaseOrderState::INVOICED];

    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        #[Autowire(service: 'sylius_admin.mailer.shipment_email_manager')]
        private readonly ShipmentEmailManagerInterface $shipmentEmails,
    ) {
    }

    /**
     * @param list<PurchaseOrder> $purchaseOrders all of the order's purchase orders
     *
     * @return bool whether the order changed
     */
    public function update(OrderInterface $order, array $purchaseOrders): bool
    {
        $live = array_values(array_filter($purchaseOrders, static fn (PurchaseOrder $p): bool => PurchaseOrderState::CANCELLED !== $p->getState()));
        $shipment = $order->getShipments()->first();
        if ([] === $live || !$shipment instanceof ShipmentInterface) {
            return false;
        }

        $changed = false;
        $tracking = [];
        $withTracking = [];
        foreach ($live as $purchaseOrder) {
            $numbers = self::customerTracking($purchaseOrder);
            foreach ($numbers as $number) {
                $tracking[$number] = true;
            }
            if ([] !== $numbers) {
                $withTracking[] = $purchaseOrder;
            }
        }
        $trackingText = [] === $tracking ? null : mb_substr(implode(', ', array_keys($tracking)), 0, 255);
        if (null !== $trackingText && $trackingText !== $shipment->getTracking()) {
            $shipment->setTracking($trackingText);
            foreach ($withTracking as $purchaseOrder) {
                $purchaseOrder->log(PurchaseOrderEventType::CustomerUpdated, 'Tracking on the customer\'s order: '.$trackingText);
            }
            $changed = true;
        }

        $allShipped = [] === array_filter($live, static fn (PurchaseOrder $p): bool => !self::isOnItsWayToTheCustomer($p));
        if ($allShipped && $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)) {
            $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
            $this->shipmentEmails->sendConfirmationEmail($shipment);
            foreach ($live as $purchaseOrder) {
                $purchaseOrder->log(PurchaseOrderEventType::CustomerUpdated, 'Customer\'s order marked shipped; shipping email sent.');
            }
            $changed = true;
        }

        return $changed;
    }

    /** @return list<string> tracking numbers the customer can follow */
    private static function customerTracking(PurchaseOrder $purchaseOrder): array
    {
        return match ($purchaseOrder->getFulfilment()) {
            Fulfilment::OrsDirect => array_values($purchaseOrder->getShipments()->map(static fn (PurchaseOrderShipment $s): string => $s->getTrackingNumber())->toArray()),
            Fulfilment::CrossDock => null === $purchaseOrder->getForwardedTracking() || '' === $purchaseOrder->getForwardedTracking() ? [] : [$purchaseOrder->getForwardedTracking()],
            Fulfilment::Unconfirmed => [],
        };
    }

    private static function isOnItsWayToTheCustomer(PurchaseOrder $purchaseOrder): bool
    {
        return match ($purchaseOrder->getFulfilment()) {
            Fulfilment::OrsDirect => in_array($purchaseOrder->getState(), self::SHIPPED, true),
            Fulfilment::CrossDock => null !== $purchaseOrder->getForwardedAt(),
            Fulfilment::Unconfirmed => false,
        };
    }
}
