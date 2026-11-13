<?php

declare(strict_types=1);

namespace App\Entity\Order;

use App\Distributor\FulfilmentRoute;
use App\Shipping\PackageProtection\PackageProtectionOrderProcessor;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Order as BaseOrder;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_order')]
class Order extends BaseOrder
{
    /**
     * Direct, cross-dock, mixed or not confirmed, from the order's purchase
     * orders (FulfilmentRoute::of); null until it's paid and has some.
     * A plain string so the Sylius admin grid can filter on it.
     */
    #[ORM\Column(name: 'fulfilment_route', type: 'string', length: 20, nullable: true)]
    private ?string $fulfilmentRoute = null;

    /**
     * The customer's package protection choice at checkout. Null = not
     * chosen yet, which counts as yes: the box starts ticked.
     */
    #[ORM\Column(name: 'package_protection', type: 'boolean', nullable: true)]
    private ?bool $packageProtection = null;

    public function getFulfilmentRoute(): ?FulfilmentRoute
    {
        return null === $this->fulfilmentRoute ? null : FulfilmentRoute::tryFrom($this->fulfilmentRoute);
    }

    public function setFulfilmentRoute(?FulfilmentRoute $fulfilmentRoute): void
    {
        $this->fulfilmentRoute = $fulfilmentRoute?->value;
    }

    public function wantsPackageProtection(): bool
    {
        return false !== $this->packageProtection;
    }

    /** The checkout box: ticked until the customer unticks it. */
    public function getPackageProtection(): bool
    {
        return $this->wantsPackageProtection();
    }

    public function setPackageProtection(?bool $packageProtection): void
    {
        $this->packageProtection = $packageProtection;
    }

    /** What the customer pays for package protection, in cents; 0 when they don't have it. */
    public function getPackageProtectionTotal(): int
    {
        return $this->getAdjustmentsTotal(PackageProtectionOrderProcessor::ADJUSTMENT_TYPE);
    }
}
