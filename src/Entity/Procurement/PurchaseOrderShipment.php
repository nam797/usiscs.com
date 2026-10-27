<?php

declare(strict_types=1);

namespace App\Entity\Procurement;

use Doctrine\ORM\Mapping as ORM;

/** A tracking number the distributor reported for a purchase order. */
#[ORM\Entity]
#[ORM\Table(name: 'app_purchase_order_shipment')]
#[ORM\UniqueConstraint(name: 'uniq_app_purchase_order_shipment_tracking', columns: ['purchase_order_id', 'tracking_number'])]
class PurchaseOrderShipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'shipments')]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\Column(name: 'tracking_number', type: 'string', length: 100)]
    private string $trackingNumber;

    /** e.g. "UPS GRD COML" */
    #[ORM\Column(name: 'carrier', type: 'string', length: 100)]
    private string $carrier;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(PurchaseOrder $purchaseOrder, string $trackingNumber, string $carrier)
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->trackingNumber = mb_substr($trackingNumber, 0, 100);
        $this->carrier = mb_substr($carrier, 0, 100);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getTrackingNumber(): string
    {
        return $this->trackingNumber;
    }

    public function getCarrier(): string
    {
        return $this->carrier;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
