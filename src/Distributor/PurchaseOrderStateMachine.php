<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderEvent;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The only way a purchase order changes state: applies a transition of the
 * "purchase_order" state machine (config/packages/workflow.yaml), which
 * refuses moves the graph doesn't allow, and logs it on the timeline.
 */
final class PurchaseOrderStateMachine
{
    public function __construct(
        #[Target('purchase_order')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    public function can(PurchaseOrder $purchaseOrder, PurchaseOrderTransition $transition): bool
    {
        return $this->workflow->can($purchaseOrder, $transition->value);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \Symfony\Component\Workflow\Exception\NotEnabledTransitionException when not allowed from the current state
     */
    public function apply(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderTransition $transition,
        ?string $message = null,
        string $actor = PurchaseOrderEvent::SYSTEM,
        array $payload = [],
    ): void {
        $this->workflow->apply($purchaseOrder, $transition->value);
        $purchaseOrder->log(PurchaseOrderEventType::of($transition), $message, $actor, $payload);
    }
}
