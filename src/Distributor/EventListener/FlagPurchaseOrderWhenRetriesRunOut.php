<?php

declare(strict_types=1);

namespace App\Distributor\EventListener;

use App\Distributor\Message\SubmitPurchaseOrder;
use App\Distributor\PurchaseOrderNotifierInterface;
use App\Distributor\PurchaseOrderState;
use App\Distributor\PurchaseOrderStateMachine;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Procurement\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Messenger has given up retrying a purchase order (about two days of
 * backoff, see config/packages/messenger.yaml): take it off the queue and
 * tell staff. The message itself moves to the "distributor_failed"
 * transport (messenger:failed:show distributor_failed).
 */
#[AsEventListener]
final class FlagPurchaseOrderWhenRetriesRunOut
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PurchaseOrderStateMachine $stateMachine,
        private readonly PurchaseOrderNotifierInterface $notifier,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof SubmitPurchaseOrder) {
            return;
        }

        $purchaseOrder = $this->entityManager->find(PurchaseOrder::class, $message->purchaseOrderId);
        if (!$purchaseOrder instanceof PurchaseOrder || PurchaseOrderState::QUEUED !== $purchaseOrder->getState()) {
            return;
        }

        $reason = sprintf(
            'Gave up after %d attempt(s). Last error: %s',
            $purchaseOrder->getAttempts(),
            $purchaseOrder->getLastError() ?? $event->getThrowable()->getMessage(),
        );
        $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Flag, $reason);
        $this->entityManager->flush();
        $this->notifier->needsAttention($purchaseOrder, $reason);
    }
}
