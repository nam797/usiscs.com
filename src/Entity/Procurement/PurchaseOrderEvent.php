<?php

declare(strict_types=1);

namespace App\Entity\Procurement;

use App\Distributor\PurchaseOrderEventType;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a purchase order's timeline. Append-only: never updated or
 * deleted, so it shows what happened and when, even after the state moves on.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_purchase_order_event')]
#[ORM\Index(name: 'idx_app_purchase_order_event_occurred_at', columns: ['occurred_at'])]
class PurchaseOrderEvent
{
    /** Actor for things the worker, crons or listeners did. */
    public const SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\Column(name: 'type', type: 'string', length: 30, enumType: PurchaseOrderEventType::class)]
    private PurchaseOrderEventType $type;

    /** The purchase order's state right after this event. */
    #[ORM\Column(name: 'state', type: 'string', length: 30)]
    private string $state;

    #[ORM\Column(name: 'message', type: 'text', nullable: true)]
    private ?string $message;

    /** "system", or the admin's email. */
    #[ORM\Column(name: 'actor', type: 'string', length: 180)]
    private string $actor;

    /**
     * Extra detail, e.g. the distributor's reply. Never credentials.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'payload', type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, mixed> $payload */
    public function __construct(PurchaseOrder $purchaseOrder, PurchaseOrderEventType $type, string $state, ?string $message, string $actor, array $payload = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->type = $type;
        $this->state = $state;
        $this->message = $message;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getType(): PurchaseOrderEventType
    {
        return $this->type;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
