<?php

declare(strict_types=1);

namespace App\Entity\Shipping;

use App\Shipping\Carrier;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;
use Sylius\Component\Shipping\Model\ShippingMethodTranslationInterface;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_shipping_method')]
class ShippingMethod extends BaseShippingMethod
{
    /**
     * Who carries orders sent this way: pre-picks the carrier when the
     * warehouse ships a cross-dock order on, and tells staff which carrier
     * to ask ORS for. Null for a method that isn't a carrier service.
     */
    #[ORM\Column(name: 'carrier', type: 'string', length: 20, nullable: true, enumType: Carrier::class)]
    private ?Carrier $carrier = null;

    public function getCarrier(): ?Carrier
    {
        return $this->carrier;
    }

    public function setCarrier(?Carrier $carrier): void
    {
        $this->carrier = $carrier;
    }

    protected function createTranslation(): ShippingMethodTranslationInterface
    {
        return new ShippingMethodTranslation();
    }
}
