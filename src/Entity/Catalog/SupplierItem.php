<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

/**
 * One source for a SKU: a distributor that carries it, with their item
 * number, how it reaches the customer (direct or cross-dock), what they
 * charge us and how many they have. A SKU can have several; each paid order
 * goes to the cheapest one with stock (App\Distributor\SourceSelector), and
 * the shop's stock is the total over all of them (VariantStockTotal).
 *
 * Kept out of the Sylius product tables so supplier data never mixes with
 * what the shop shows; the selling price stays the channel pricing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_supplier_item')]
#[ORM\UniqueConstraint(name: 'uniq_app_supplier_item_sku_supplier', columns: ['sku', 'supplier'])]
class SupplierItem
{
    public const ORS_NASCO = 'ors_nasco';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'sources')]
    #[ORM\JoinColumn(name: 'variant_id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    /** Our SKU (the variant code), kept here for lookups by code. */
    #[ORM\Column(name: 'sku', type: 'string', length: 255)]
    private string $sku;

    /** The distributor (a DistributorInterface code, e.g. "ors_nasco"). */
    #[ORM\Column(name: 'supplier', type: 'string', length: 50)]
    private string $supplier;

    /** The SKU as this distributor writes it (ORS "012-92-500-7.5-8"); null = same as ours. */
    #[ORM\Column(name: 'supplier_sku', type: 'string', length: 255, nullable: true)]
    private ?string $supplierSku = null;

    #[ORM\Column(name: 'fulfilment', type: 'string', length: 20, enumType: Fulfilment::class, options: ['default' => 'unconfirmed'])]
    private Fulfilment $fulfilment = Fulfilment::Unconfirmed;

    /** Staff can switch a source off; orders and stock then ignore it. */
    #[ORM\Column(name: 'enabled', type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /** Our cost per supplier selling unit, in cents; null when unknown. */
    #[ORM\Column(name: 'cost_in_cents', type: 'integer', nullable: true)]
    private ?int $costInCents = null;

    #[ORM\Column(name: 'unit_sell', type: 'string', length: 20, nullable: true)]
    private ?string $unitSell = null;

    #[ORM\Column(name: 'unit_conversion', type: 'string', length: 50, nullable: true)]
    private ?string $unitConversion = null;

    /** The supplier's message, e.g. "Product is Inactive"; null when all is well. */
    #[ORM\Column(name: 'status', type: 'string', length: 255, nullable: true)]
    private ?string $status = null;

    /** When the cost was last synced (or the source was created). */
    #[ORM\Column(name: 'synced_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    /** How many the distributor has; null = unknown (no stock feed). */
    #[ORM\Column(name: 'stock', type: 'integer', nullable: true)]
    private ?int $stock = null;

    #[ORM\Column(name: 'stock_synced_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $stockSyncedAt = null;

    public function __construct(ProductVariant $variant, string $supplier)
    {
        $this->variant = $variant;
        $this->sku = (string) $variant->getCode();
        $this->supplier = $supplier;
        $this->syncedAt = new \DateTimeImmutable();
        $variant->addSource($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getSupplier(): string
    {
        return $this->supplier;
    }

    /** The SKU as the distributor writes it. */
    public function getSupplierSku(): string
    {
        return $this->supplierSku ?? $this->sku;
    }

    public function setSupplierSku(?string $supplierSku): void
    {
        $supplierSku = null === $supplierSku ? null : trim($supplierSku);
        $this->supplierSku = '' === $supplierSku || $supplierSku === $this->sku ? null : $supplierSku;
    }

    public function getFulfilment(): Fulfilment
    {
        return $this->fulfilment;
    }

    public function setFulfilment(Fulfilment $fulfilment): void
    {
        $this->fulfilment = $fulfilment;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCostInCents(): ?int
    {
        return $this->costInCents;
    }

    /** Set by hand, for a distributor without a price feed. */
    public function setCostInCents(?int $costInCents): void
    {
        $this->costInCents = $costInCents;
    }

    public function getUnitSell(): ?string
    {
        return $this->unitSell;
    }

    public function getUnitConversion(): ?string
    {
        return $this->unitConversion;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    /** @return bool whether anything changed */
    public function record(?int $costInCents, ?string $unitSell, ?string $unitConversion, ?string $status): bool
    {
        $changed = [$this->costInCents, $this->unitSell, $this->unitConversion, $this->status] !== [$costInCents, $unitSell, $unitConversion, $status];

        $this->costInCents = $costInCents;
        $this->unitSell = $unitSell;
        $this->unitConversion = $unitConversion;
        $this->status = $status;
        $this->syncedAt = new \DateTimeImmutable();

        return $changed;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function getStockSyncedAt(): ?\DateTimeImmutable
    {
        return $this->stockSyncedAt;
    }

    /** @return bool whether it changed */
    public function recordStock(?int $stock): bool
    {
        $changed = $stock !== $this->stock;
        $this->stock = null === $stock ? null : max(0, $stock);
        $this->stockSyncedAt = new \DateTimeImmutable();

        return $changed;
    }

    /** Can take orders: switched on and its fulfilment is known. */
    public function isUsable(): bool
    {
        return $this->enabled && Fulfilment::Unconfirmed !== $this->fulfilment;
    }

    public function __toString(): string
    {
        return sprintf('%s @ %s', $this->sku, $this->supplier);
    }
}
