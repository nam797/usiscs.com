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
 * Also says whose sheet the product data came from: a SKU several
 * distributors carry gets its name, price and description from one of
 * them; the others' sheets only update their own source (SupplierItem).
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

    /** The distributor whose import sheet the product data comes from. */
    #[ORM\Column(name: 'distributor', type: 'string', length: 50)]
    private string $distributor;

    #[ORM\Column(name: 'source', type: 'string', length: 50)]
    private string $source;

    /** @var array<string, scalar|null> */
    #[ORM\Column(name: 'imported_values', type: 'json')]
    private array $importedValues = [];

    #[ORM\Column(name: 'last_imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastImportedAt;

    /** @param array<string, scalar|null> $importedValues */
    public function __construct(string $productCode, string $source, array $importedValues, string $distributor = SupplierItem::ORS_NASCO)
    {
        $this->productCode = $productCode;
        $this->distributor = $distributor;
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

    public function getDistributor(): string
    {
        return $this->distributor;
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
