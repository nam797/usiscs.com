<?php

declare(strict_types=1);

namespace App\Distributor\EventListener;

use App\Distributor\Payment\AuthorizedPaymentHandler;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Entity\Procurement\PurchaseOrderEvent;
use App\Repository\Procurement\PurchaseOrderRepository;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Puts payment changes made outside AuthorizedPaymentHandler on the
 * order's purchase order timelines, so staff see the whole story in one
 * place:
 *
 *  - a held payment charged (Complete on the order's payment in the admin)
 *  - a hold released (the payment cancelled)
 *  - a refund (the payment's Refund button, or one made in Stripe and
 *    reported by its webhook)
 *
 * Says who did it: the admin's email, or "Stripe" when there was no admin.
 * Charges and releases AuthorizedPaymentHandler makes are logged there,
 * with the reason, so they're skipped here. Payments taken in full at
 * checkout aren't logged: there was nothing held.
 */
final class LogPaymentOnPurchaseOrders
{
    public function __construct(
        private readonly AuthorizedPaymentHandler $payments,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly Security $security,
    ) {
    }

    #[AsEventListener(event: 'workflow.sylius_payment.completed.complete')]
    #[AsEventListener(event: 'workflow.sylius_payment.completed.cancel')]
    #[AsEventListener(event: 'workflow.sylius_payment.completed.refund')]
    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        $transition = $event->getTransition();
        $order = $payment instanceof PaymentInterface ? $payment->getOrder() : null;
        if (!$payment instanceof PaymentInterface || !$order instanceof OrderInterface || null === $transition || $this->payments->isSettling()) {
            return;
        }

        $wasHeld = in_array(PaymentInterface::STATE_AUTHORIZED, $transition->getFroms(), true);
        [$type, $what, $onCancelled] = match ($transition->getName()) {
            'complete' => [PurchaseOrderEventType::PaymentCaptured, 'Card payment captured', false],
            'cancel' => [PurchaseOrderEventType::PaymentReleased, 'Card hold released', true],
            'refund' => [PurchaseOrderEventType::PaymentRefunded, 'Payment refunded', null],
            default => [null, '', false],
        };
        if (null === $type || ('refund' !== $transition->getName() && !$wasHeld)) {
            return;
        }

        $user = $this->security->getUser();
        $actor = $user instanceof AdminUserInterface && null !== $user->getEmail() ? $user->getEmail() : 'Stripe';
        $message = sprintf(
            '%s, %s: %s.',
            $what,
            AuthorizedPaymentHandler::money((int) $payment->getAmount(), $order),
            'Stripe' === $actor ? 'reported by Stripe' : 'in the Sylius admin',
        );

        foreach ($this->purchaseOrders->findForOrder($order) as $purchaseOrder) {
            // A capture concerns what will still ship, a release what was cancelled, a refund all of it.
            if (null === $onCancelled || $onCancelled === (PurchaseOrderState::CANCELLED === $purchaseOrder->getState())) {
                $purchaseOrder->log($type, $message, 'Stripe' === $actor ? PurchaseOrderEvent::SYSTEM : $actor);
            }
        }
    }
}
