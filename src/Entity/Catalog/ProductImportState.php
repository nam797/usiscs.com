<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the last import wrote to a product, field by field. Lets a re-import
 * tell "nobody touched this field" (safe to update) from "an admin changed
 * it" (keep the admin's value). See App\Catalog\Import\FieldMerger.
 *
 * Keyed by product code rather than a foreign key, so deleting a product in
 * the admin never fails on this table; an orphaned row is harmless and is
 * replaced if the SKU is ever imported again.
 *
 * Also the list of SKUs the supplier syncs look after, with the supplier's
 * own item number when it isn't a valid Sylius code (ORS "012-92-500-7.5-8"
 * is product code "012-92-500-7_5-8").
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_product_import_state')]
class ProductImportState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'product_code', type: 'string', length: 255, unique: true)]
    private string $productCode;

    #[ORM\Column(name: 'supplier_sku', type: 'string', length: 255, nullable: true)]
    private ?string $supplierSku = null;

    #[ORM\Column(name: 'source', type: 'string', length: 50)]
    private string $source;

    /** @var array<string, scalar|null> */
    #[ORM\Column(name: 'imported_values', type: 'json')]
    private array $importedValues = [];

    #[ORM\Column(name: 'last_imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastImportedAt;

    /** @param array<string, scalar|null> $importedValues */
    public function __construct(string $productCode, string $source, array $importedValues, ?string $supplierSku = null)
    {
        $this->productCode = $productCode;
        $this->setSupplierSku($supplierSku);
        $this->source = $source;
        $this->importedValues = $importedValues;
        $this->lastImportedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductCode(): string
    {
        return $this->productCode;
    }

    /** The SKU as the supplier writes it; the product code when they're the same. */
    public function getSupplierSku(): string
    {
        return $this->supplierSku ?? $this->productCode;
    }

    public function setSupplierSku(?string $supplierSku): void
    {
        $this->supplierSku = $supplierSku === $this->productCode ? null : $supplierSku;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /** @return array<string, scalar|null> */
    public function getImportedValues(): array
    {
        return $this->importedValues;
    }

    /** @param array<string, scalar|null> $importedValues */
    public function recordImport(string $source, array $importedValues): void
    {
        $this->source = $source;
        $this->importedValues = $importedValues;
        $this->lastImportedAt = new \DateTimeImmutable();
    }

    public function getLastImportedAt(): \DateTimeImmutable
    {
        return $this->lastImportedAt;
    }
}
