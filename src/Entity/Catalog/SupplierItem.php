<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the supplier (ORS Nasco) charges us for a SKU, from the price sync.
 * Kept out of the Sylius product tables so supplier data never mixes with
 * what the shop shows; the selling price stays the channel pricing.
 *
 * Keyed by product/variant code (not a foreign key), like
 * ProductImportState, which also holds the ORS item number when it differs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_supplier_item')]
class SupplierItem
{
    public const ORS_NASCO = 'ors_nasco';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'sku', type: 'string', length: 255, unique: true)]
    private string $sku;

    #[ORM\Column(name: 'supplier', type: 'string', length: 50)]
    private string $supplier;

    /** Our cost per supplier selling unit, in cents; null when the supplier gave none. */
    #[ORM\Column(name: 'cost_in_cents', type: 'integer', nullable: true)]
    private ?int $costInCents = null;

    #[ORM\Column(name: 'unit_sell', type: 'string', length: 20, nullable: true)]
    private ?string $unitSell = null;

    #[ORM\Column(name: 'unit_conversion', type: 'string', length: 50, nullable: true)]
    private ?string $unitConversion = null;

    /** The supplier's message, e.g. "Product is Inactive"; null when all is well. */
    #[ORM\Column(name: 'status', type: 'string', length: 255, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'synced_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    public function __construct(string $sku, string $supplier)
    {
        $this->sku = $sku;
        $this->supplier = $supplier;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getSupplier(): string
    {
        return $this->supplier;
    }

    public function getCostInCents(): ?int
    {
        return $this->costInCents;
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
}
