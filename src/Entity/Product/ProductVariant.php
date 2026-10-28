<?php

declare(strict_types=1);

namespace App\Entity\Product;

use App\Catalog\Unit\PackUnit;
use App\Entity\Catalog\SupplierItem;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ProductVariant as BaseProductVariant;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_product_variant')]
class ProductVariant extends BaseProductVariant
{
    /** What the price is for, e.g. "CA" (see PackUnit); null when not set. */
    #[ORM\Column(name: 'sell_unit', type: 'string', length: 10, nullable: true)]
    private ?string $sellUnit = null;

    #[ORM\Column(name: 'pack_quantity', type: 'integer', nullable: true)]
    private ?int $packQuantity = null;

    #[ORM\Column(name: 'pack_unit', type: 'string', length: 10, nullable: true)]
    private ?string $packUnit = null;

    /** Why shipping needs a closer look, e.g. "Aerosol: check carrier and hazmat rules". */
    #[ORM\Column(name: 'shipping_note', type: 'string', length: 255, nullable: true)]
    private ?string $shippingNote = null;

    /**
     * Who we can order it from: one per distributor, with their SKU,
     * fulfilment, cost and stock.
     *
     * @var Collection<int, SupplierItem>
     */
    #[ORM\OneToMany(targetEntity: SupplierItem::class, mappedBy: 'variant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['supplier' => 'ASC'])]
    private Collection $sources;

    public function __construct()
    {
        parent::__construct();
        $this->sources = new ArrayCollection();
    }

    public function getPackUnit(): ?PackUnit
    {
        return null === $this->sellUnit ? null : PackUnit::of($this->sellUnit, $this->packQuantity ?? 1, $this->packUnit);
    }

    public function setPackUnit(?PackUnit $unit): void
    {
        $this->sellUnit = $unit?->sellUnit;
        $this->packQuantity = $unit?->quantity;
        $this->packUnit = $unit?->innerUnit;
    }

    public function getShippingNote(): ?string
    {
        return $this->shippingNote;
    }

    public function setShippingNote(?string $shippingNote): void
    {
        $this->shippingNote = '' === $shippingNote ? null : $shippingNote;
    }

    /** @return Collection<int, SupplierItem> */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    /** Called by SupplierItem's constructor. */
    public function addSource(SupplierItem $source): void
    {
        if (!$this->sources->contains($source)) {
            $this->sources->add($source);
        }
    }

    public function removeSource(SupplierItem $source): void
    {
        $this->sources->removeElement($source);
    }

    public function getSource(string $distributor): ?SupplierItem
    {
        foreach ($this->sources as $source) {
            if ($source->getSupplier() === $distributor) {
                return $source;
            }
        }

        return null;
    }

    /** This distributor's source, added (unconfirmed, enabled) when there's none yet. */
    public function sourceFor(string $distributor): SupplierItem
    {
        return $this->getSource($distributor) ?? new SupplierItem($this, $distributor);
    }

    protected function createTranslation(): ProductVariantTranslationInterface
    {
        return new ProductVariantTranslation();
    }
}
