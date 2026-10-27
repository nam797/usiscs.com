<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

final class PurchaseOrderWorkflowTest extends TestCase
{
    use PurchaseOrderTestHelpers;

    public function testTheConfigMatchesTheStateAndTransitionClasses(): void
    {
        $config = self::workflowConfig();

        self::assertEqualsCanonicalizing(array_keys(PurchaseOrderState::LABELS), $config['places']);
        self::assertEqualsCanonicalizing(array_map(static fn (PurchaseOrderTransition $t): string => $t->value, PurchaseOrderTransition::cases()), array_keys($config['transitions']));
        self::assertSame(PurchaseOrderState::QUEUED, $config['initial_marking']);
        foreach (PurchaseOrderTransition::cases() as $transition) {
            self::assertSame($transition->value, PurchaseOrderEventType::of($transition)->value);
        }
    }

    public function testTheHappyPathIsLoggedOnTheTimeline(): void
    {
        $stateMachine = self::stateMachine();
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', 'fake', Fulfilment::OrsDirect);

        $stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Start, 'Attempt 1');
        $stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Submit, 'Sent', 'system', ['ref' => 'X1']);

        self::assertSame(PurchaseOrderState::SUBMITTED, $purchaseOrder->getState());
        $events = $purchaseOrder->getEvents()->toArray();
        self::assertCount(2, $events);
        self::assertSame(PurchaseOrderEventType::Start, $events[0]->getType());
        self::assertSame(PurchaseOrderState::SUBMITTING, $events[0]->getState());
        self::assertSame(PurchaseOrderEventType::Submit, $events[1]->getType());
        self::assertSame(['ref' => 'X1'], $events[1]->getPayload());
    }

    public function testItRefusesMovesTheGraphDoesNotAllow(): void
    {
        $stateMachine = self::stateMachine();
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', 'fake', Fulfilment::OrsDirect);

        self::assertFalse($stateMachine->can($purchaseOrder, PurchaseOrderTransition::Ship));
        self::assertFalse($stateMachine->can($purchaseOrder, PurchaseOrderTransition::MarkSubmitted));
        self::assertTrue($stateMachine->can($purchaseOrder, PurchaseOrderTransition::Cancel));

        $this->expectException(NotEnabledTransitionException::class);
        $stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Ship);
    }

    public function testStaffCanRetryOrPlaceByHandWhatNeedsAttention(): void
    {
        $stateMachine = self::stateMachine();
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', 'fake', Fulfilment::OrsDirect);
        $stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Flag, 'Refused');

        self::assertTrue($stateMachine->can($purchaseOrder, PurchaseOrderTransition::Retry));
        self::assertTrue($stateMachine->can($purchaseOrder, PurchaseOrderTransition::MarkSubmitted));
        self::assertTrue($stateMachine->can($purchaseOrder, PurchaseOrderTransition::Cancel));
        self::assertFalse($stateMachine->can($purchaseOrder, PurchaseOrderTransition::Start));

        $purchaseOrder->setDispatchedAt(new \DateTimeImmutable());
        $stateMachine->apply($purchaseOrder, PurchaseOrderTransition::Retry);
        self::assertSame(PurchaseOrderState::QUEUED, $purchaseOrder->getState());
    }

    public function testAQueuedOrderNotYetOnTheQueueIsWaitingForDispatch(): void
    {
        $purchaseOrder = new PurchaseOrder(new Order(), '000001-1', 'fake', Fulfilment::OrsDirect);
        self::assertTrue($purchaseOrder->isWaitingForDispatch());

        $purchaseOrder->setDispatchedAt(new \DateTimeImmutable());
        self::assertFalse($purchaseOrder->isWaitingForDispatch());
    }
}
