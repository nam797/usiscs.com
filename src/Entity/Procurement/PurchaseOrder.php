<?php

declare(strict_types=1);

namespace App\Entity\Procurement;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Distributor\Warehouse\WarehouseStatus;
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

    /** When it entered its current state; the watchdog measures how long it's been stuck from this. */
    #[ORM\Column(name: 'state_changed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $stateChangedAt;

    /** Last time the distributor was asked for its status. */
    #[ORM\Column(name: 'last_checked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    /** Last time staff were emailed that it's stuck; at most once a day. */
    #[ORM\Column(name: 'alerted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alertedAt = null;

    /**
     * Cross-dock only: when the distributor's delivery should reach our
     * warehouse. Filled in from the ship date when tracking first arrives
     * (PurchaseOrderTracker); staff can change it.
     */
    #[ORM\Column(name: 'expected_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expectedAt = null;

    /** Cross-dock only: when our warehouse checked the delivery in, and who did. */
    #[ORM\Column(name: 'received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(name: 'received_by', type: 'string', length: 180, nullable: true)]
    private ?string $receivedBy = null;

    /** Cross-dock only: when we shipped it on to the customer, how, and who did. */
    #[ORM\Column(name: 'forwarded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $forwardedAt = null;

    #[ORM\Column(name: 'forwarded_by', type: 'string', length: 180, nullable: true)]
    private ?string $forwardedBy = null;

    #[ORM\Column(name: 'forwarded_carrier', type: 'string', length: 100, nullable: true)]
    private ?string $forwardedCarrier = null;

    #[ORM\Column(name: 'forwarded_tracking', type: 'string', length: 100, nullable: true)]
    private ?string $forwardedTracking = null;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderLine::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, PurchaseOrderShipment> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderShipment::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $shipments;

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
        $this->stateChangedAt = $this->createdAt;
        $this->lines = new ArrayCollection();
        $this->shipments = new ArrayCollection();
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
        if ($state !== $this->state) {
            $this->stateChangedAt = new \DateTimeImmutable();
        }
        $this->state = $state;
    }

    public function getStateChangedAt(): \DateTimeImmutable
    {
        return $this->stateChangedAt;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): void
    {
        $this->reference = null === $reference || '' === trim($reference) ? null : trim($reference);
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function markChecked(): void
    {
        $this->lastCheckedAt = new \DateTimeImmutable();
    }

    public function getAlertedAt(): ?\DateTimeImmutable
    {
        return $this->alertedAt;
    }

    public function markAlerted(): void
    {
        $this->alertedAt = new \DateTimeImmutable();
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
        if (null !== $reference && '' !== trim($reference)) {
            $this->setReference($reference);
        }
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

    /** @return Collection<int, PurchaseOrderShipment> */
    public function getShipments(): Collection
    {
        return $this->shipments;
    }

    /** @return bool false when this tracking number is already recorded */
    public function addShipment(string $trackingNumber, string $carrier): bool
    {
        foreach ($this->shipments as $shipment) {
            if ($shipment->getTrackingNumber() === $trackingNumber) {
                return false;
            }
        }
        $this->shipments->add(new PurchaseOrderShipment($this, $trackingNumber, $carrier));

        return true;
    }

    /** When the distributor's first tracking number arrived: as near as we get to its ship date. */
    public function getDistributorShippedAt(): ?\DateTimeImmutable
    {
        $first = $this->shipments->first();

        return false === $first ? null : $first->getCreatedAt();
    }

    public function isCrossDock(): bool
    {
        return Fulfilment::CrossDock === $this->fulfilment;
    }

    /** Where it is for our warehouse, as of today; null unless it's a live cross-dock purchase order. */
    public function getWarehouseStatus(): ?WarehouseStatus
    {
        return WarehouseStatus::of($this, new \DateTimeImmutable('today'));
    }

    public function getExpectedAt(): ?\DateTimeImmutable
    {
        return $this->expectedAt;
    }

    /** @return bool whether the date changed */
    public function changeExpectedAt(?\DateTimeImmutable $expectedAt, string $actor = PurchaseOrderEvent::SYSTEM, ?string $note = null): bool
    {
        $expectedAt = $expectedAt?->setTime(0, 0);
        if ($expectedAt?->format('Y-m-d') === $this->expectedAt?->format('Y-m-d')) {
            return false;
        }

        $this->expectedAt = $expectedAt;
        $message = null === $expectedAt ? 'Expected at the warehouse: not known.' : 'Expected at the warehouse on '.$expectedAt->format('D Y-m-d').'.';
        $this->log(PurchaseOrderEventType::ExpectedDateChanged, $message.(null === $note || '' === $note ? '' : ' '.$note), $actor);

        return true;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getReceivedBy(): ?string
    {
        return $this->receivedBy;
    }

    /** Our warehouse checked the delivery in. */
    public function markReceived(string $actor, ?string $note = null): void
    {
        $this->receivedAt = new \DateTimeImmutable();
        $this->receivedBy = mb_substr($actor, 0, 180);
        $this->log(PurchaseOrderEventType::Received, null === $note || '' === $note ? null : $note, $actor);
    }

    public function getForwardedAt(): ?\DateTimeImmutable
    {
        return $this->forwardedAt;
    }

    public function getForwardedBy(): ?string
    {
        return $this->forwardedBy;
    }

    public function getForwardedCarrier(): ?string
    {
        return $this->forwardedCarrier;
    }

    public function getForwardedTracking(): ?string
    {
        return $this->forwardedTracking;
    }

    /** We shipped it on from our warehouse to the customer. */
    public function markForwarded(string $carrier, string $trackingNumber, string $actor, ?string $note = null): void
    {
        $this->forwardedAt = new \DateTimeImmutable();
        $this->forwardedBy = mb_substr($actor, 0, 180);
        $this->forwardedCarrier = mb_substr(trim($carrier), 0, 100);
        $this->forwardedTracking = mb_substr(trim($trackingNumber), 0, 100);
        $this->log(
            PurchaseOrderEventType::ShippedToCustomer,
            trim($this->forwardedCarrier.' '.$this->forwardedTracking).(null === $note || '' === $note ? '' : '. '.$note),
            $actor,
        );
    }

    public function getLineForSupplierSku(string $supplierSku): ?PurchaseOrderLine
    {
        foreach ($this->lines as $line) {
            if (0 === strcasecmp($line->getSupplierSku(), $supplierSku)) {
                return $line;
            }
        }

        return null;
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
