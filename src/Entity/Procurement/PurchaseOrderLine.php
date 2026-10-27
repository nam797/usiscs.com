<?php

declare(strict_types=1);

namespace App\Entity\Procurement;

use App\Entity\Order\OrderItem;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * One SKU on a purchase order, copied from the shop order item when the
 * order was paid, so later catalog changes don't rewrite what we ordered.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_purchase_order_line')]
class PurchaseOrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderItem $orderItem;

    /** Our SKU (the variant code). */
    #[ORM\Column(name: 'variant_code', type: 'string', length: 255)]
    private string $variantCode;

    /** The SKU as the distributor writes it (ORS "012-92-500-7.5-8"); often the same. */
    #[ORM\Column(name: 'supplier_sku', type: 'string', length: 255)]
    private string $supplierSku;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    private string $name;

    /** In our selling unit, which is the distributor's for imported SKUs. */
    #[ORM\Column(name: 'quantity', type: 'integer')]
    private int $quantity;

    /** e.g. "CA"; null when the variant has no pack unit. */
    #[ORM\Column(name: 'unit', type: 'string', length: 10, nullable: true)]
    private ?string $unit;

    /** What the customer paid per unit, in cents, after discounts. */
    #[ORM\Column(name: 'unit_price_in_cents', type: 'integer')]
    private int $unitPriceInCents;

    /** What the distributor charges us per unit at order time (SupplierItem); null if unknown. */
    #[ORM\Column(name: 'unit_cost_in_cents', type: 'integer', nullable: true)]
    private ?int $unitCostInCents;

    /** Shipped so far, from the distributor's order status. */
    #[ORM\Column(name: 'quantity_shipped', type: 'integer', options: ['default' => 0])]
    private int $quantityShipped = 0;

    /** On back-order at the distributor right now. */
    #[ORM\Column(name: 'quantity_backordered', type: 'integer', options: ['default' => 0])]
    private int $quantityBackordered = 0;

    public function __construct(
        PurchaseOrder $purchaseOrder,
        ?OrderItemInterface $orderItem,
        string $variantCode,
        string $supplierSku,
        string $name,
        int $quantity,
        ?string $unit,
        int $unitPriceInCents,
        ?int $unitCostInCents,
    ) {
        $this->purchaseOrder = $purchaseOrder;
        $this->orderItem = $orderItem instanceof OrderItem ? $orderItem : null;
        $this->variantCode = $variantCode;
        $this->supplierSku = $supplierSku;
        $this->name = $name;
        $this->quantity = $quantity;
        $this->unit = $unit;
        $this->unitPriceInCents = $unitPriceInCents;
        $this->unitCostInCents = $unitCostInCents;
        $purchaseOrder->addLine($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getOrderItem(): ?OrderItemInterface
    {
        return $this->orderItem;
    }

    public function getVariantCode(): string
    {
        return $this->variantCode;
    }

    public function getSupplierSku(): string
    {
        return $this->supplierSku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getUnitPriceInCents(): int
    {
        return $this->unitPriceInCents;
    }

    public function getUnitCostInCents(): ?int
    {
        return $this->unitCostInCents;
    }

    public function getQuantityShipped(): int
    {
        return $this->quantityShipped;
    }

    public function getQuantityBackordered(): int
    {
        return $this->quantityBackordered;
    }

    /** @return bool whether the back-ordered quantity changed */
    public function recordProgress(int $shipped, int $backordered): bool
    {
        $changed = $backordered !== $this->quantityBackordered;
        $this->quantityShipped = max(0, $shipped);
        $this->quantityBackordered = max(0, $backordered);

        return $changed;
    }
}
