<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Emails the order's channel contact email (Sylius admin > Channels), like
 * the lead emails. Runs in the worker, after the state is changed: a missing
 * address or mail failure is logged, never thrown, since the purchase order
 * itself shows what needs doing.
 */
final class PurchaseOrderNotifier implements PurchaseOrderNotifierInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly DistributorRegistry $distributors,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
    ) {
    }

    public function needsAttention(PurchaseOrder $purchaseOrder, string $reason): void
    {
        $this->send(
            $purchaseOrder,
            sprintf('Action needed: purchase order %s (%s)', $purchaseOrder->getNumber(), $this->distributors->nameOf($purchaseOrder->getDistributor())),
            'emails/purchase_order_needs_attention.txt.twig',
            ['reason' => $reason],
        );
    }

    public function manualEntryNeeded(PurchaseOrder $purchaseOrder): void
    {
        $this->send(
            $purchaseOrder,
            sprintf('Place purchase order %s with %s', $purchaseOrder->getNumber(), $this->distributors->nameOf($purchaseOrder->getDistributor())),
            'emails/purchase_order_manual_entry.txt.twig',
        );
    }

    public function stuck(array $stuck): void
    {
        $this->send(
            $stuck[0]['purchaseOrder'],
            sprintf('%d purchase order%s need%s a look', count($stuck), 1 === count($stuck) ? '' : 's', 1 === count($stuck) ? 's' : ''),
            'emails/purchase_orders_stuck.txt.twig',
            ['stuck' => $stuck],
        );
    }

    /** @param array<string, mixed> $context */
    private function send(PurchaseOrder $purchaseOrder, string $subject, string $template, array $context = []): void
    {
        $channel = $purchaseOrder->getOrder()->getChannel();
        $to = $channel?->getContactEmail();
        if (null === $to || '' === $to) {
            $this->logger->warning(sprintf('Purchase order %s: not emailed ("%s"), the channel has no contact email configured.', $purchaseOrder->getNumber(), $subject));

            return;
        }

        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->from, $channel->getName() ?? 'USISCS Website'))
                ->to(new Address($to))
                ->subject($subject)
                ->text($this->twig->render($template, $context + [
                    'purchase_order' => $purchaseOrder,
                    'distributor_name' => $this->distributors->nameOf($purchaseOrder->getDistributor()),
                ])));
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Failed to email about purchase order %s: %s', $purchaseOrder->getNumber(), $exception->getMessage()));
        }
    }
}
