<?php

declare(strict_types=1);

namespace App\Command;

use App\Distributor\DistributorSettings;
use App\Distributor\PurchaseOrderStateMachine;
use App\Distributor\PurchaseOrderTransition;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Safety net for the purchase order queue, run by an Upsun cron. Normally
 * every queued purchase order is put on the worker queue the moment it's
 * saved (PurchaseOrderOutbox); this catches the ones that weren't:
 *
 *  - queued but never dispatched (sending the message failed)
 *  - dispatched long ago with no attempt since (message lost)
 *  - stuck "sending" (the worker died mid-attempt): queued again; the next
 *    attempt first asks the distributor whether it already has the order
 *
 * Paused distributors are left alone. Safe to run any time: the worker
 * ignores a message for a purchase order that is no longer queued.
 */
#[AsCommand(
    name: 'app:distributor:dispatch',
    description: 'Re-queue purchase orders whose message was never sent, was lost, or whose attempt was cut off.',
)]
final class DispatchPurchaseOrdersCommand extends Command
{
    /** Longer than the longest retry delay (6 h + jitter, config/packages/messenger.yaml). */
    private const LOST_AFTER = '-8 hours';

    private const UNDISPATCHED_AFTER = '-5 minutes';

    /** An attempt is one HTTP call with a timeout well under this. */
    private const STUCK_AFTER = '-1 hour';

    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly DistributorSettings $settings,
        private readonly PurchaseOrderStateMachine $stateMachine,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $stuck = $this->purchaseOrders->findStuckSubmitting($now->modify(self::STUCK_AFTER));
        foreach ($stuck as $purchaseOrder) {
            $this->stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Requeue, sprintf('Attempt %d was cut off (the worker stopped); trying again.', $purchaseOrder->getAttempts()));
            $purchaseOrder->setDispatchedAt(null);
        }

        $stale = $this->purchaseOrders->findStaleQueued($now->modify(self::UNDISPATCHED_AFTER), $now->modify(self::LOST_AFTER), $this->settings->pausedCodes());
        foreach ($stale as $purchaseOrder) {
            $purchaseOrder->setDispatchedAt(null);
        }

        // PurchaseOrderOutbox dispatches them all after this flush.
        $this->entityManager->flush();

        foreach ([...$stuck, ...$stale] as $purchaseOrder) {
            $io->writeln(sprintf('Queued %s (%s)', $purchaseOrder->getNumber(), $purchaseOrder->getDistributor()));
        }
        $io->success(sprintf('%d cut-off attempt(s) and %d stale purchase order(s) queued again.', count($stuck), count($stale)));

        return Command::SUCCESS;
    }
}
