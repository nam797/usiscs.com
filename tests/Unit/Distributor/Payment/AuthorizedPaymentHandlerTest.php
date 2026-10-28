<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor\Payment;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\Payment\AuthorizedPaymentHandler;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderNotifierInterface;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderEvent;
use App\Repository\Procurement\PurchaseOrderRepository;
use App\Tests\Unit\Distributor\PurchaseOrderTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class AuthorizedPaymentHandlerTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $emailed;

    protected function setUp(): void
    {
        $this->emailed = new \ArrayObject();
    }

    public function testItCapturesAHeldPaymentAndSaysSoOnTheLivePurchaseOrders(): void
    {
        [$order, $payment] = self::order(PaymentInterface::STATE_AUTHORIZED);
        $live = new PurchaseOrder($order, '000001-1', FakeDistributor::CODE, Fulfilment::OrsDirect);
        $cancelled = new PurchaseOrder($order, '000001-2', FakeDistributor::CODE, Fulfilment::CrossDock);
        self::stateMachine()->apply($cancelled, PurchaseOrderTransition::Cancel);

        self::assertTrue($this->handler([$live, $cancelled])->capture($order, 'test shipped'));

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(PurchaseOrderEventType::PaymentCaptured, $live->getLastEvent()?->getType());
        self::assertSame('Card payment captured, 144.86 USD: test shipped.', $live->getLastEvent()->getMessage());
        self::assertNotSame(PurchaseOrderEventType::PaymentCaptured, $cancelled->getLastEvent()?->getType());
        self::assertCount(0, $this->emailed);
    }

    public function testNothingHappensWithoutAHeldPayment(): void
    {
        [$order] = self::order(PaymentInterface::STATE_COMPLETED);

        self::assertFalse($this->handler([])->capture($order, 'x'));
        self::assertFalse($this->handler([])->release($order, 'x'));
    }

    public function testWhenStripeRefusesNothingIsRecordedAndStaffAreEmailed(): void
    {
        [$order, $payment] = self::order(PaymentInterface::STATE_AUTHORIZED);
        $purchaseOrder = new PurchaseOrder($order, '000001-1', FakeDistributor::CODE, Fulfilment::OrsDirect);

        self::assertFalse($this->handler([$purchaseOrder], stripeRefuses: true)->capture($order, 'test shipped'));

        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState(), 'reloaded as it was');
        self::assertSame(PurchaseOrderEventType::PaymentProblem, $purchaseOrder->getLastEvent()?->getType());
        self::assertCount(1, $this->emailed);
        self::assertStringContainsString('Could not capture the card payment of 144.86 USD for order #000001 (test shipped): card expired', (string) ($this->emailed[0] ?? ''));
    }

    public function testAStripeAnswerThatIsNotACaptureCountsAsAFailure(): void
    {
        [$order, $payment] = self::order(PaymentInterface::STATE_AUTHORIZED);
        $payment->setDetails(['payment_intent' => ['status' => 'requires_capture']]);
        $purchaseOrder = new PurchaseOrder($order, '000001-1', FakeDistributor::CODE, Fulfilment::OrsDirect);

        self::assertFalse($this->handler([$purchaseOrder])->capture($order, 'test shipped'));
        self::assertStringContainsString('Stripe says the payment is "requires_capture"', (string) ($this->emailed[0] ?? ''));
    }

    public function testEveryPurchaseOrderCancelledMeansNothingWillShip(): void
    {
        [$order] = self::order(PaymentInterface::STATE_AUTHORIZED);
        $a = new PurchaseOrder($order, '000001-1', FakeDistributor::CODE, Fulfilment::OrsDirect);
        $b = new PurchaseOrder($order, '000001-2', FakeDistributor::CODE, Fulfilment::OrsDirect);
        self::stateMachine()->apply($a, PurchaseOrderTransition::Cancel);

        self::assertFalse(AuthorizedPaymentHandler::allCancelled([$a, $b]));
        self::stateMachine()->apply($b, PurchaseOrderTransition::Cancel);
        self::assertTrue(AuthorizedPaymentHandler::allCancelled([$a, $b]));
        self::assertFalse(AuthorizedPaymentHandler::allCancelled([]));
    }

    /** @return array{Order, Payment} */
    private static function order(string $paymentState): array
    {
        $order = new Order();
        $order->setNumber('000001');
        $order->setCurrencyCode('USD');
        $payment = new Payment();
        $payment->setAmount(14486);
        $payment->setCurrencyCode('USD');
        $payment->setState($paymentState);
        $order->addPayment($payment);

        return [$order, $payment];
    }

    /** @param list<PurchaseOrder> $purchaseOrders */
    private function handler(array $purchaseOrders, bool $stripeRefuses = false): AuthorizedPaymentHandler
    {
        $stateMachine = new class($stripeRefuses) implements StateMachineInterface {
            public function __construct(private readonly bool $refuses)
            {
            }

            public function can(object $subject, string $graphName, string $transition): bool
            {
                return $subject instanceof PaymentInterface && PaymentInterface::STATE_AUTHORIZED === $subject->getState();
            }

            public function apply(object $subject, string $graphName, string $transition, array $context = []): void
            {
                \assert($subject instanceof PaymentInterface);
                $subject->setState(PaymentTransitions::TRANSITION_COMPLETE === $transition ? PaymentInterface::STATE_COMPLETED : PaymentInterface::STATE_CANCELLED);
                if ($this->refuses) {
                    throw new \RuntimeException('card expired');
                }
            }

            public function getEnabledTransitions(object $subject, string $graphName): array
            {
                return [];
            }

            public function getTransitionFromState(object $subject, string $graphName, string $fromState): ?string
            {
                return null;
            }

            public function getTransitionToState(object $subject, string $graphName, string $toState): ?string
            {
                return null;
            }
        };

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Reloading puts a refused payment back as it was.
        $entityManager->method('refresh')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof PaymentInterface) {
                $entity->setState(PaymentInterface::STATE_AUTHORIZED);
            }
        });
        $repository = $this->createMock(PurchaseOrderRepository::class);
        $repository->method('findForOrder')->willReturn($purchaseOrders);

        $notifier = new class($this->emailed) implements PurchaseOrderNotifierInterface {
            /** @param \ArrayObject<int, string> $emailed */
            public function __construct(private readonly \ArrayObject $emailed)
            {
            }

            public function needsAttention(PurchaseOrder $purchaseOrder, string $reason): void
            {
                $this->emailed[] = $reason;
            }

            public function manualEntryNeeded(PurchaseOrder $purchaseOrder): void
            {
            }

            public function stuck(array $stuck): void
            {
            }
        };

        return new AuthorizedPaymentHandler($stateMachine, $entityManager, $repository, $notifier);
    }
}
