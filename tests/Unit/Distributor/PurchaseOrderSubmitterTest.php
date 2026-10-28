<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorInterface;
use App\Distributor\DistributorOrderStatus;
use App\Distributor\DistributorRegistry;
use App\Distributor\DistributorUnavailableException;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderNotifierInterface;
use App\Distributor\PurchaseOrderPlanner;
use App\Distributor\PurchaseOrderState;
use App\Distributor\PurchaseOrderSubmitter;
use App\Distributor\PurchaseOrderTransition;
use App\Distributor\SubmissionResult;
use App\Distributor\SourceSelector;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PurchaseOrderSubmitterTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $notified;

    protected function setUp(): void
    {
        $this->notified = new \ArrayObject();
    }

    public function testAnAcceptedOrderIsSubmittedWithTheDistributorsNumber(): void
    {
        $purchaseOrder = $this->claimed();

        $this->submitter(new FakeDistributor('accept'))->submit($purchaseOrder, false);

        self::assertSame(PurchaseOrderState::SUBMITTED, $purchaseOrder->getState());
        self::assertSame('FAKE-000001-1', $purchaseOrder->getReference());
        self::assertNotNull($purchaseOrder->getSubmittedAt());
        self::assertSame([], $this->notified->getArrayCopy());
    }

    public function testWhenTheDistributorIsDownItIsQueuedAgainAndTheErrorRethrownForARetry(): void
    {
        $purchaseOrder = $this->claimed();

        try {
            $this->submitter(new FakeDistributor('down'))->submit($purchaseOrder, false);
            self::fail('Expected the exception to be rethrown');
        } catch (DistributorUnavailableException) {
        }

        self::assertSame(PurchaseOrderState::QUEUED, $purchaseOrder->getState());
        $event = $purchaseOrder->getLastEvent();
        self::assertNotNull($event);
        self::assertSame(PurchaseOrderEventType::Requeue, $event->getType());
        self::assertStringContainsString('Attempt 1 failed: Test distributor is down', (string) $event->getMessage());
        self::assertNotNull($purchaseOrder->getLastError());
        // Not waiting for dispatch: Messenger's retry has the message.
        self::assertFalse($purchaseOrder->isWaitingForDispatch());
    }

    public function testARefusedOrderNeedsAttentionAndStaffAreTold(): void
    {
        $purchaseOrder = $this->claimed();

        $this->submitter(new FakeDistributor('reject'))->submit($purchaseOrder, false);

        self::assertSame(PurchaseOrderState::NEEDS_ATTENTION, $purchaseOrder->getState());
        self::assertSame(['attention: Test distributor refused it: Test distributor refused the order (FAKE_DISTRIBUTOR_MODE=reject)'], $this->notified->getArrayCopy());
    }

    public function testWithoutAnOrderApiStaffAreAskedToPlaceItByHand(): void
    {
        $purchaseOrder = $this->claimed();

        $this->submitter(new FakeDistributor('manual'))->submit($purchaseOrder, false);

        self::assertSame(PurchaseOrderState::AWAITING_MANUAL_ENTRY, $purchaseOrder->getState());
        self::assertSame(['manual'], $this->notified->getArrayCopy());
    }

    public function testManualEntryCanBeSwitchedOnForADistributorWithAnApi(): void
    {
        $purchaseOrder = $this->claimed();
        $distributor = $this->recordingDistributor();

        $this->submitter($distributor)->submit($purchaseOrder, true);

        self::assertSame(PurchaseOrderState::AWAITING_MANUAL_ENTRY, $purchaseOrder->getState());
        self::assertSame(0, $distributor->submitted);
    }

    public function testARetryFirstAsksWhetherTheDistributorAlreadyHasIt(): void
    {
        $purchaseOrder = $this->claimed(attempts: 2);
        $distributor = $this->recordingDistributor(existing: 'R-77');

        $this->submitter($distributor)->submit($purchaseOrder, false);

        self::assertSame(PurchaseOrderState::SUBMITTED, $purchaseOrder->getState());
        self::assertSame('R-77', $purchaseOrder->getReference());
        self::assertSame(0, $distributor->submitted, 'must not place it twice');
    }

    public function testTheFirstAttemptDoesNotAsk(): void
    {
        $purchaseOrder = $this->claimed();
        $distributor = $this->recordingDistributor(existing: 'R-77');

        $this->submitter($distributor)->submit($purchaseOrder, false);

        self::assertSame(1, $distributor->submitted);
        self::assertSame('NEW-1', $purchaseOrder->getReference());
    }

    public function testAnUnconfirmedFulfilmentIsNeverSent(): void
    {
        $purchaseOrder = $this->claimed(fulfilment: Fulfilment::Unconfirmed);
        $distributor = $this->recordingDistributor();

        $this->submitter($distributor)->submit($purchaseOrder, false);

        self::assertSame(PurchaseOrderState::NEEDS_ATTENTION, $purchaseOrder->getState());
        self::assertSame(0, $distributor->submitted);
        self::assertCount(1, $this->notified);
    }

    private function claimed(int $attempts = 1, Fulfilment $fulfilment = Fulfilment::OrsDirect): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', FakeDistributor::CODE, $fulfilment);
        for ($i = 0; $i < $attempts; ++$i) {
            $purchaseOrder->recordAttempt();
        }
        $purchaseOrder->setDispatchedAt(new \DateTimeImmutable());
        self::stateMachine()->apply($purchaseOrder, PurchaseOrderTransition::Start);

        return $purchaseOrder;
    }

    /** @return DistributorInterface&object{submitted: int} */
    private function recordingDistributor(?string $existing = null): DistributorInterface
    {
        return new class($existing) implements DistributorInterface {
            public int $submitted = 0;

            public function __construct(private readonly ?string $existing)
            {
            }

            public function getCode(): string
            {
                return FakeDistributor::CODE;
            }

            public function getName(): string
            {
                return 'Recorder';
            }

            public function canSubmit(): bool
            {
                return true;
            }

            public function submit(PurchaseOrder $purchaseOrder): SubmissionResult
            {
                ++$this->submitted;

                return new SubmissionResult('NEW-'.$this->submitted);
            }

            public function findExisting(PurchaseOrder $purchaseOrder): ?string
            {
                return $this->existing;
            }

            public function fetchStatus(PurchaseOrder $purchaseOrder): ?DistributorOrderStatus
            {
                return null;
            }
        };
    }

    private function submitter(DistributorInterface $distributor): PurchaseOrderSubmitter
    {
        $registry = new DistributorRegistry([$distributor]);
        $notifier = new class($this->notified) implements PurchaseOrderNotifierInterface {
            /** @param \ArrayObject<int, string> $notified */
            public function __construct(private readonly \ArrayObject $notified)
            {
            }

            public function needsAttention(PurchaseOrder $purchaseOrder, string $reason): void
            {
                $this->notified[] = 'attention: '.$reason;
            }

            public function manualEntryNeeded(PurchaseOrder $purchaseOrder): void
            {
                $this->notified[] = 'manual';
            }

            public function stuck(array $stuck): void
            {
                $this->notified[] = 'stuck';
            }
        };

        return new PurchaseOrderSubmitter($registry, new PurchaseOrderPlanner($registry, new SourceSelector($registry)), self::stateMachine(), $notifier, new NullLogger());
    }
}
