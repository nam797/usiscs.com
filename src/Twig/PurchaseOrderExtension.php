<?php

declare(strict_types=1);

namespace App\Twig;

use App\Distributor\DistributorRegistry;
use App\Distributor\PurchaseOrderState;
use App\Entity\Procurement\PurchaseOrder;
use App\Repository\Procurement\PurchaseOrderRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Purchase orders in admin templates (backoffice and the Sylius order page). */
final class PurchaseOrderExtension extends AbstractExtension
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly DistributorRegistry $distributors,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('purchase_orders_for', $this->purchaseOrdersFor(...)),
            new TwigFunction('purchase_order_state_label', PurchaseOrderState::label(...)),
            new TwigFunction('distributor_name', $this->distributors->nameOf(...)),
        ];
    }

    /** @return list<PurchaseOrder> */
    public function purchaseOrdersFor(OrderInterface $order): array
    {
        return null === $order->getId() ? [] : $this->purchaseOrders->findForOrder($order);
    }
}
