<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Procurement\PurchaseOrder;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\ShipmentEmailManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Passes what the distributor reports on to the customer's order, for
 * purchase orders the distributor ships straight to the customer:
 *
 *  - tracking numbers go on the order's shipment (customers see them in
 *    their account)
 *  - once every purchase order of the order has shipped direct, the
 *    shipment is marked shipped and Sylius's "your order has shipped" email
 *    goes out, as if staff had clicked Ship in the admin
 *
 * Cross-dock purchase orders ship to our warehouse, and we ship those
 * orders on ourselves, so an order with any of them is left to staff.
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
        $direct = array_values(array_filter($live, static fn (PurchaseOrder $p): bool => Fulfilment::OrsDirect === $p->getFulfilment()));
        $shipment = $order->getShipments()->first();
        if ([] === $direct || !$shipment instanceof ShipmentInterface) {
            return false;
        }

        $changed = false;
        $tracking = [];
        foreach ($direct as $purchaseOrder) {
            foreach ($purchaseOrder->getShipments() as $purchaseOrderShipment) {
                $tracking[$purchaseOrderShipment->getTrackingNumber()] = true;
            }
        }
        $trackingText = [] === $tracking ? null : mb_substr(implode(', ', array_keys($tracking)), 0, 255);
        if (null !== $trackingText && $trackingText !== $shipment->getTracking()) {
            $shipment->setTracking($trackingText);
            foreach ($direct as $purchaseOrder) {
                $purchaseOrder->log(PurchaseOrderEventType::CustomerUpdated, 'Tracking on the customer\'s order: '.$trackingText);
            }
            $changed = true;
        }

        $allShippedDirect = count($direct) === count($live)
            && [] === array_filter($direct, static fn (PurchaseOrder $p): bool => !in_array($p->getState(), self::SHIPPED, true));
        if ($allShippedDirect && $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)) {
            $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
            $this->shipmentEmails->sendConfirmationEmail($shipment);
            foreach ($direct as $purchaseOrder) {
                $purchaseOrder->log(PurchaseOrderEventType::CustomerUpdated, 'Customer\'s order marked shipped; shipping email sent.');
            }
            $changed = true;
        }

        return $changed;
    }
}
