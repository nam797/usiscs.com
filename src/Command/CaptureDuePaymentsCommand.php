<?php

declare(strict_types=1);

namespace App\Command;

use App\Distributor\Payment\AuthorizedPaymentHandler;
use App\Entity\Order\Order;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Run by an Upsun cron. Stripe releases a held card payment after about 7
 * days. A payment still only authorized after app.capture_authorized_after_days
 * (6) is charged anyway, so a slow shipment never loses the payment:
 * usually because the distributor can't report shipments yet (ORS orders
 * entered by hand). Each one is noted on the order's purchase orders;
 * failures email staff (AuthorizedPaymentHandler).
 */
#[AsCommand(
    name: 'app:payments:capture-due',
    description: 'Charge card payments still only authorized after a few days, before Stripe releases the hold.',
)]
final class CaptureDuePaymentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizedPaymentHandler $payments,
        #[Autowire(param: 'app.capture_authorized_after_days')]
        private readonly int $afterDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = new \DateTimeImmutable(sprintf('-%d days', $this->afterDays));

        /** @var list<Order> $orders */
        $orders = $this->entityManager->getRepository(Order::class)->createQueryBuilder('o')
            ->where('o.checkoutState = :completed')
            ->andWhere('o.paymentState = :authorized')
            ->andWhere('o.checkoutCompletedAt < :before')
            ->setParameter('completed', OrderCheckoutStates::STATE_COMPLETED)
            ->setParameter('authorized', OrderPaymentStates::STATE_AUTHORIZED)
            ->setParameter('before', $before)
            ->orderBy('o.id')
            ->getQuery()
            ->getResult();

        $captured = 0;
        foreach ($orders as $order) {
            if ($this->payments->capture($order, sprintf('still held after %d days, before Stripe releases it', $this->afterDays))) {
                ++$captured;
                $io->writeln(sprintf('Order #%s: captured.', $order->getNumber()));
            } else {
                $io->warning(sprintf('Order #%s: could not capture; staff were emailed.', $order->getNumber()));
            }
        }

        $io->success(sprintf('%d of %d held payment(s) captured.', $captured, \count($orders)));

        return $captured === \count($orders) ? Command::SUCCESS : Command::FAILURE;
    }
}
