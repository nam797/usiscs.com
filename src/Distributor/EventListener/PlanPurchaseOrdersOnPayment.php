<?php

declare(strict_types=1);

namespace App\Distributor\EventListener;

use App\Distributor\PurchaseOrderPlanner;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * An order is fully paid: create its purchase orders. They're persisted
 * here and saved by the flush that saves the payment (Sylius flushes after
 * every payment transition); PurchaseOrderOutbox then queues them.
 *
 * Only once per order, so a refund-and-pay-again or a replayed webhook
 * doesn't order the goods twice.
 */
#[AsEventListener(event: 'workflow.sylius_order_payment.completed.pay')]
final class PlanPurchaseOrdersOnPayment
{
    public function __construct(
        private readonly PurchaseOrderPlanner $planner,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface || null === $order->getId() || [] !== $this->purchaseOrders->findForOrder($order)) {
            return;
        }

        foreach ($this->planner->plan($order) as $purchaseOrder) {
            $this->entityManager->persist($purchaseOrder);
        }
    }
}
