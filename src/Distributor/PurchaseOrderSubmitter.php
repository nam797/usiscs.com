<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;
use Psr\Log\LoggerInterface;

/**
 * One attempt at sending a purchase order the worker has claimed (state
 * "submitting"). Ends in exactly one of:
 *
 *   submitted              the distributor has it
 *   awaiting manual entry  no order API, or switched to manual: staff are emailed
 *   needs attention        can't be sent as it is, or refused: staff are emailed
 *   queued + exception     failed in a way worth retrying: the caller rethrows
 *                          and Messenger retries with backoff
 *
 * Changes the purchase order only; the caller flushes.
 */
final class PurchaseOrderSubmitter
{
    public function __construct(
        private readonly DistributorRegistry $distributors,
        private readonly PurchaseOrderPlanner $planner,
        private readonly PurchaseOrderStateMachine $stateMachine,
        private readonly PurchaseOrderNotifierInterface $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws \Throwable when the attempt should be retried; the purchase order is queued again */
    public function submit(PurchaseOrder $purchaseOrder, bool $manualEntry): void
    {
        $problems = $this->planner->problems($purchaseOrder);
        $distributor = $this->distributors->get($purchaseOrder->getDistributor());
        if ([] !== $problems || null === $distributor) {
            $this->flag($purchaseOrder, implode(' ', $problems));

            return;
        }

        if ($manualEntry || !$distributor->canSubmit()) {
            $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::RequestManualEntry, $manualEntry
                ? sprintf('Manual entry is switched on for %s: staff emailed to place it.', $distributor->getName())
                : sprintf('%s has no order API yet: staff emailed to place it.', $distributor->getName()));
            $this->notifier->manualEntryNeeded($purchaseOrder);

            return;
        }

        try {
            // An earlier attempt may have reached the distributor even though
            // we never heard back: never place the same order twice.
            if ($purchaseOrder->getAttempts() > 1) {
                $existing = $distributor->findExisting($purchaseOrder);
                if (null !== $existing) {
                    $purchaseOrder->markSubmitted($existing);
                    $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Submit, sprintf(
                        '%s already had it as order %s (an earlier attempt went through).',
                        $distributor->getName(),
                        $existing,
                    ));

                    return;
                }
            }

            $result = $distributor->submit($purchaseOrder);
        } catch (OrderRejectedException $exception) {
            $purchaseOrder->setLastError($exception->getMessage());
            $this->flag($purchaseOrder, sprintf('%s refused it: %s', $distributor->getName(), $exception->getMessage()));

            return;
        } catch (\Throwable $exception) {
            // Down, timed out, or a bug: retry. Messenger gives up after its
            // retry limit (FlagPurchaseOrderWhenRetriesRunOut).
            $purchaseOrder->setLastError($exception->getMessage());
            $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Requeue, sprintf(
                'Attempt %d failed: %s',
                $purchaseOrder->getAttempts(),
                $exception->getMessage(),
            ));
            if (!$exception instanceof DistributorUnavailableException) {
                $this->logger->error(sprintf('Purchase order %s: unexpected error from %s', $purchaseOrder->getNumber(), $distributor->getCode()), ['exception' => $exception]);
            }

            throw $exception;
        }

        $purchaseOrder->markSubmitted($result->reference);
        $this->stateMachine->apply(
            $purchaseOrder,
            PurchaseOrderTransition::Submit,
            $result->message ?? sprintf('Sent to %s%s.', $distributor->getName(), null === $result->reference ? '' : ' as order '.$result->reference),
            payload: $result->details,
        );
    }

    private function flag(PurchaseOrder $purchaseOrder, string $reason): void
    {
        $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Flag, $reason);
        $this->notifier->needsAttention($purchaseOrder, $reason);
    }
}
