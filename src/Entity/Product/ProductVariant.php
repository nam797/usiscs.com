<?php

declare(strict_types=1);

namespace App\Entity\Product;

use App\Catalog\Fulfilment\Fulfilment;
use App\Catalog\Unit\PackUnit;
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

    #[ORM\Column(name: 'fulfilment', type: 'string', length: 20, enumType: Fulfilment::class, options: ['default' => 'unconfirmed'])]
    private Fulfilment $fulfilment = Fulfilment::Unconfirmed;

    /** Why shipping needs a closer look, e.g. "Aerosol: check carrier and hazmat rules". */
    #[ORM\Column(name: 'shipping_note', type: 'string', length: 255, nullable: true)]
    private ?string $shippingNote = null;

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

    public function getFulfilment(): Fulfilment
    {
        return $this->fulfilment;
    }

    public function setFulfilment(Fulfilment $fulfilment): void
    {
        $this->fulfilment = $fulfilment;
    }

    public function getShippingNote(): ?string
    {
        return $this->shippingNote;
    }

    public function setShippingNote(?string $shippingNote): void
    {
        $this->shippingNote = '' === $shippingNote ? null : $shippingNote;
    }

    protected function createTranslation(): ProductVariantTranslationInterface
    {
        return new ProductVariantTranslation();
    }
}
