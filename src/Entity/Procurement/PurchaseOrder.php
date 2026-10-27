<?php

declare(strict_types=1);

namespace App\Entity\Procurement;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Entity\Order\Order;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * What we order from one distributor for one shop order: the order's items
 * that this distributor ships, the same way (direct or cross-dock). A paid
 * order gets one per distributor and fulfilment (PurchaseOrderPlanner).
 *
 * The row is also the outbox: a queued purchase order that hasn't been
 * dispatched yet gets a message on the worker queue after the flush that
 * saved it (PurchaseOrderOutbox), and app:distributor:dispatch picks up any
 * that slipped through. Its state changes only through the
 * "purchase_order" state machine (PurchaseOrderStateMachine), which also
 * logs every change as a PurchaseOrderEvent.
 */
#[ORM\Entity(repositoryClass: PurchaseOrderRepository::class)]
#[ORM\Table(name: 'app_purchase_order')]
#[ORM\Index(name: 'idx_app_purchase_order_state', columns: ['state'])]
class PurchaseOrder
{
    /** Distributor code for items whose variant has none set. */
    public const UNASSIGNED = 'unassigned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private Order $order;

    /** Our PO number, "{order number}-{n}": what the distributor knows us by (ORS "custpo"). */
    #[ORM\Column(name: 'number', type: 'string', length: 64, unique: true)]
    private string $number;

    #[ORM\Column(name: 'distributor', type: 'string', length: 50)]
    private string $distributor;

    #[ORM\Column(name: 'fulfilment', type: 'string', length: 20, enumType: Fulfilment::class)]
    private Fulfilment $fulfilment;

    #[ORM\Column(name: 'state', type: 'string', length: 30)]
    private string $state = PurchaseOrderState::QUEUED;

    /** The distributor's own order number, once it has the order. */
    #[ORM\Column(name: 'reference', type: 'string', length: 64, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'attempts', type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** When a message was last put on the worker queue for it; null = still to send. */
    #[ORM\Column(name: 'dispatched_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(name: 'last_attempt_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(name: 'submitted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderLine::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, PurchaseOrderEvent> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderEvent::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    public function __construct(OrderInterface $order, string $number, string $distributor, Fulfilment $fulfilment)
    {
        if (!$order instanceof Order) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', Order::class, $order::class));
        }

        $this->order = $order;
        $this->number = $number;
        $this->distributor = $distributor;
        $this->fulfilment = $fulfilment;
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getDistributor(): string
    {
        return $this->distributor;
    }

    public function setDistributor(string $distributor): void
    {
        $this->distributor = $distributor;
    }

    public function getFulfilment(): Fulfilment
    {
        return $this->fulfilment;
    }

    public function setFulfilment(Fulfilment $fulfilment): void
    {
        $this->fulfilment = $fulfilment;
    }

    public function getState(): string
    {
        return $this->state;
    }

    /** For the state machine's marking store only; use PurchaseOrderStateMachine. */
    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDispatchedAt(): ?\DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function setDispatchedAt(?\DateTimeImmutable $dispatchedAt): void
    {
        $this->dispatchedAt = $dispatchedAt;
    }

    /** Queued and not on the worker queue yet: the outbox sends it after the next flush. */
    public function isWaitingForDispatch(): bool
    {
        return PurchaseOrderState::QUEUED === $this->state && null === $this->dispatchedAt;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function recordAttempt(): void
    {
        ++$this->attempts;
        $this->lastAttemptAt = new \DateTimeImmutable();
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /** The distributor has it; $reference is their order number, when known. */
    public function markSubmitted(?string $reference): void
    {
        $this->reference = null === $reference || '' === trim($reference) ? $this->reference : trim($reference);
        $this->submittedAt = new \DateTimeImmutable();
        $this->lastError = null;
    }

    /** @return Collection<int, PurchaseOrderLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(PurchaseOrderLine $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    /** @return Collection<int, PurchaseOrderEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /** @param array<string, mixed> $payload */
    public function log(PurchaseOrderEventType $type, ?string $message = null, string $actor = PurchaseOrderEvent::SYSTEM, array $payload = []): PurchaseOrderEvent
    {
        $event = new PurchaseOrderEvent($this, $type, $this->state, $message, $actor, $payload);
        $this->events->add($event);

        return $event;
    }

    public function getLastEvent(): ?PurchaseOrderEvent
    {
        $last = $this->events->last();

        return false === $last ? null : $last;
    }
}
