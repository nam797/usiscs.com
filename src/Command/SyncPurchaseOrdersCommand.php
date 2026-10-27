<?php

declare(strict_types=1);

namespace App\Command;

use App\Distributor\CustomerShipmentUpdater;
use App\Distributor\DistributorUnavailableException;
use App\Distributor\PurchaseOrderNotifierInterface;
use App\Distributor\PurchaseOrderTracker;
use App\Distributor\PurchaseOrderWatchdog;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\Exception\StateMachineExecutionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run by an Upsun cron. For every open purchase order, asks its
 * distributor where it is (PurchaseOrderTracker) and passes tracking and
 * "shipped" on to the customer's order (CustomerShipmentUpdater). Then
 * emails staff one list of purchase orders stuck too long
 * (PurchaseOrderWatchdog).
 *
 * Each purchase order is saved on its own. When a distributor fails (down,
 * not configured), its remaining purchase orders are skipped until the
 * next run.
 */
#[AsCommand(
    name: 'app:distributor:sync-orders',
    description: 'Update open purchase orders from their distributors (status, tracking, back-orders) and email staff about stuck ones.',
)]
final class SyncPurchaseOrdersCommand extends Command
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderTracker $tracker,
        private readonly CustomerShipmentUpdater $customerShipments,
        private readonly PurchaseOrderWatchdog $watchdog,
        private readonly PurchaseOrderNotifierInterface $notifier,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-alerts', null, InputOption::VALUE_NONE, 'Don\'t email staff about stuck purchase orders');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $failedDistributors = [];
        $updated = 0;
        $ordersToUpdate = [];
        foreach ($this->purchaseOrders->findInStates(PurchaseOrderTracker::TRACKED_STATES) as $purchaseOrder) {
            $distributor = $purchaseOrder->getDistributor();
            if (isset($failedDistributors[$distributor])) {
                continue;
            }

            try {
                if ($this->tracker->track($purchaseOrder)) {
                    ++$updated;
                    $io->writeln(sprintf('%s: %s', $purchaseOrder->getNumber(), $purchaseOrder->getLastEvent()?->getMessage() ?? $purchaseOrder->getState()), OutputInterface::VERBOSITY_VERBOSE);
                }
                $this->entityManager->flush();
                $ordersToUpdate[(int) $purchaseOrder->getOrder()->getId()] = $purchaseOrder->getOrder();
            } catch (DistributorUnavailableException $exception) {
                // Thrown before anything changed: nothing to undo.
                $failedDistributors[$distributor] = $exception->getMessage();
            }
        }

        $customersUpdated = 0;
        foreach ($ordersToUpdate as $order) {
            try {
                if ($this->customerShipments->update($order, $this->purchaseOrders->findForOrder($order))) {
                    ++$customersUpdated;
                }
                $this->entityManager->flush();
            } catch (StateMachineExecutionException $exception) {
                // e.g. staff already shipped or cancelled it in the admin.
                $this->entityManager->refresh($order);
                $io->warning(sprintf('Order #%s: could not update its shipment: %s', $order->getNumber(), $exception->getMessage()));
            }
        }

        foreach ($failedDistributors as $distributor => $error) {
            $io->warning(sprintf('%s: skipped the rest of its purchase orders: %s', $distributor, $error));
        }

        $stuck = $this->watchdog->stuck($this->purchaseOrders->findInStates(array_keys(PurchaseOrderWatchdog::LIMITS)), new \DateTimeImmutable());
        if ([] !== $stuck && !$input->getOption('no-alerts')) {
            $this->notifier->stuck($stuck);
            foreach ($stuck as $row) {
                $row['purchaseOrder']->markAlerted();
            }
            $this->entityManager->flush();
        }
        foreach ($stuck as $row) {
            $io->writeln(sprintf('Stuck: %s: %s', $row['purchaseOrder']->getNumber(), $row['reason']));
        }

        $io->success(sprintf('%d purchase order(s) updated, %d customer order(s) updated, %d stuck.', $updated, $customersUpdated, count($stuck)));

        return Command::SUCCESS;
    }
}
