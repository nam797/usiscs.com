<?php

declare(strict_types=1);

namespace App\Distributor\Payment;

use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderNotifierInterface;
use App\Distributor\PurchaseOrderState;
use App\Entity\Procurement\PurchaseOrder;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Card payments are only authorized at checkout: Stripe holds the money,
 * and purchase orders go to the distributors straight away. This charges
 * or releases the hold:
 *
 *  - capture(): when the first purchase order ships (CapturePaymentWhenDistributorShips),
 *    or before Stripe's 7-day hold runs out (app:payments:capture-due).
 *    The whole amount, once.
 *  - release(): when every purchase order was cancelled, so the customer
 *    is never charged for what we can't supply.
 *
 * Both apply Sylius's payment transition ("complete" / "cancel"); the
 * Stripe plugin turns that into the capture or cancellation at Stripe.
 * Saves straight away, so a capture is never left half-recorded. If Stripe
 * refuses, nothing is saved, the order keeps its hold, and staff are
 * emailed.
 */
final class AuthorizedPaymentHandler
{
    /** Set while this charges or releases a payment, so LogPaymentOnPurchaseOrders leaves the timeline to it. */
    private bool $settling = false;

    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly EntityManagerInterface $entityManager,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderNotifierInterface $notifier,
    ) {
    }

    /** @return bool whether a payment was captured */
    public function capture(OrderInterface $order, string $why): bool
    {
        return $this->apply($order, PaymentTransitions::TRANSITION_COMPLETE, PaymentInterface::STATE_COMPLETED, $why);
    }

    /** @return bool whether a hold was released */
    public function release(OrderInterface $order, string $why): bool
    {
        return $this->apply($order, PaymentTransitions::TRANSITION_CANCEL, PaymentInterface::STATE_CANCELLED, $why);
    }

    private function apply(OrderInterface $order, string $transition, string $expectedState, string $why): bool
    {
        $authorized = array_values(array_filter(
            $order->getPayments()->toArray(),
            fn (PaymentInterface $payment): bool => PaymentInterface::STATE_AUTHORIZED === $payment->getState()
                && $this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition),
        ));
        if ([] === $authorized) {
            return false;
        }

        $captured = PaymentTransitions::TRANSITION_COMPLETE === $transition;
        $amount = array_sum(array_map(static fn (PaymentInterface $p): int => (int) $p->getAmount(), $authorized));
        $purchaseOrders = $this->purchaseOrders->findForOrder($order);

        $this->settling = true;
        try {
            foreach ($authorized as $payment) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
                if ($expectedState !== $payment->getState()) {
                    throw new \RuntimeException(sprintf('the payment is "%s"', $payment->getState()));
                }
                // Stripe's own answer, which the plugin copies onto the payment.
                $intent = $payment->getDetails()['payment_intent'] ?? null;
                $status = is_array($intent) ? ($intent['status'] ?? null) : null;
                if (is_string($status) && $status !== ($captured ? 'succeeded' : 'canceled')) {
                    throw new \RuntimeException(sprintf('Stripe says the payment is "%s"', $status));
                }
            }
        } catch (\Throwable $exception) {
            // Nothing half-done is saved: the payment is still authorized at Stripe and here.
            foreach ([...$authorized, $order] as $entity) {
                $this->entityManager->refresh($entity);
            }
            $problem = sprintf(
                'Could not %s the card payment of %s for order #%s (%s): %s. Do it in the Sylius admin (order page, payment), or in the Stripe dashboard.',
                $captured ? 'capture' : 'release',
                self::money($amount, $order),
                $order->getNumber(),
                $why,
                $exception->getMessage(),
            );
            $first = $purchaseOrders[0] ?? null;
            if (null !== $first) {
                $first->log(PurchaseOrderEventType::PaymentProblem, $problem);
                $this->entityManager->flush();
                $this->notifier->needsAttention($first, $problem);
            }

            return false;
        } finally {
            $this->settling = false;
        }

        foreach ($purchaseOrders as $purchaseOrder) {
            if ($captured === (PurchaseOrderState::CANCELLED !== $purchaseOrder->getState())) {
                $purchaseOrder->log(
                    $captured ? PurchaseOrderEventType::PaymentCaptured : PurchaseOrderEventType::PaymentReleased,
                    sprintf('%s %s: %s.', $captured ? 'Card payment captured,' : 'Card hold released,', self::money($amount, $order), $why),
                );
            }
        }
        $this->entityManager->flush();

        return true;
    }

    public function isSettling(): bool
    {
        return $this->settling;
    }

    public static function money(int $cents, OrderInterface $order): string
    {
        return sprintf('%s %s', number_format($cents / 100, 2), $order->getCurrencyCode() ?? 'USD');
    }

    /** @param list<PurchaseOrder> $purchaseOrders */
    public static function allCancelled(array $purchaseOrders): bool
    {
        return [] !== $purchaseOrders && [] === array_filter($purchaseOrders, static fn (PurchaseOrder $p): bool => PurchaseOrderState::CANCELLED !== $p->getState());
    }
}
