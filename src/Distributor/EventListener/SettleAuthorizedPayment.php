<?php

declare(strict_types=1);

namespace App\Distributor\EventListener;

use App\Distributor\DistributorRegistry;
use App\Distributor\Event\PurchaseOrderTransitioned;
use App\Distributor\Payment\AuthorizedPaymentHandler;
use App\Distributor\PurchaseOrderTransition;
use App\Repository\Procurement\PurchaseOrderRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Charges or releases a held card payment as the order's purchase orders
 * move (AuthorizedPaymentHandler):
 *
 *  - the first purchase order the distributor ships (partly or fully, or
 *    straight to invoiced): charge the whole order
 *  - every purchase order cancelled: release the hold
 *
 * Orders already paid at checkout have nothing held, so nothing happens.
 */
final class SettleAuthorizedPayment
{
    public function __construct(
        private readonly AuthorizedPaymentHandler $payments,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly DistributorRegistry $distributors,
    ) {
    }

    #[AsEventListener]
    public function __invoke(PurchaseOrderTransitioned $event): void
    {
        $purchaseOrder = $event->purchaseOrder;
        $order = $purchaseOrder->getOrder();

        if (in_array($event->transition, [PurchaseOrderTransition::ShipPartially, PurchaseOrderTransition::Ship, PurchaseOrderTransition::Invoice], true)) {
            $this->payments->capture($order, sprintf('%s shipped purchase order %s', $this->distributors->nameOf($purchaseOrder->getDistributor()), $purchaseOrder->getNumber()));
        } elseif (PurchaseOrderTransition::Cancel === $event->transition && AuthorizedPaymentHandler::allCancelled($this->purchaseOrders->findForOrder($order))) {
            $this->payments->release($order, 'every purchase order for it was cancelled');
        }
    }
}
