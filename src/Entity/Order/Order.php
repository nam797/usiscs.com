<?php

declare(strict_types=1);

namespace App\Entity\Order;

use App\Distributor\FulfilmentRoute;
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

    public function getFulfilmentRoute(): ?FulfilmentRoute
    {
        return null === $this->fulfilmentRoute ? null : FulfilmentRoute::tryFrom($this->fulfilmentRoute);
    }

    public function setFulfilmentRoute(?FulfilmentRoute $fulfilmentRoute): void
    {
        $this->fulfilmentRoute = $fulfilmentRoute?->value;
    }
}
