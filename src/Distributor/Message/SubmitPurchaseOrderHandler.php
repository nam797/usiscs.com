<?php

declare(strict_types=1);

namespace App\Distributor\Message;

use App\Distributor\DistributorSettings;
use App\Distributor\PurchaseOrderState;
use App\Distributor\PurchaseOrderStateMachine;
use App\Distributor\PurchaseOrderSubmitter;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Procurement\PurchaseOrder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs in the worker (messenger:consume distributor). First claims the
 * purchase order in a short locked transaction (queued -> submitting), so
 * two deliveries of the same message can't both send it; then makes the
 * attempt outside the transaction, so a slow distributor never holds a lock.
 *
 * Every outcome is saved before anything is rethrown: the timeline keeps
 * failed attempts too.
 */
#[AsMessageHandler(bus: 'app.distributor_bus')]
final class SubmitPurchaseOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PurchaseOrderStateMachine $stateMachine,
        private readonly PurchaseOrderSubmitter $submitter,
        private readonly DistributorSettings $settings,
    ) {
    }

    public function __invoke(SubmitPurchaseOrder $message): void
    {
        $purchaseOrder = $this->entityManager->wrapInTransaction(function () use ($message): ?PurchaseOrder {
            $purchaseOrder = $this->entityManager->find(PurchaseOrder::class, $message->purchaseOrderId, LockMode::PESSIMISTIC_WRITE);
            // Gone, already sent, or taken off the queue by staff: nothing to do.
            if (!$purchaseOrder instanceof PurchaseOrder || PurchaseOrderState::QUEUED !== $purchaseOrder->getState()) {
                return null;
            }
            // Paused: leave it queued. Resuming the distributor sends it again.
            if ($this->settings->for($purchaseOrder->getDistributor())->isPaused()) {
                return null;
            }

            $purchaseOrder->recordAttempt();
            $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Start, sprintf('Attempt %d', $purchaseOrder->getAttempts()));

            return $purchaseOrder;
        });

        if (null === $purchaseOrder) {
            return;
        }

        try {
            $this->submitter->submit($purchaseOrder, $this->settings->for($purchaseOrder->getDistributor())->isManualEntry());
        } finally {
            $this->entityManager->flush();
        }
    }
}
