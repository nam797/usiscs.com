<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Procurement\PurchaseOrder;

/**
 * How a whole shop order reaches the customer, from its purchase orders:
 * all direct from the distributor, all through our warehouse, or some of
 * each. Stored on the order (Order::fulfilmentRoute) so the Sylius admin
 * order list can show and filter it.
 */
enum FulfilmentRoute: string
{
    case Direct = 'direct';
    case CrossDock = 'cross_dock';
    case Mixed = 'mixed';
    /** Some items have no confirmed fulfilment yet. */
    case Unconfirmed = 'unconfirmed';

    /**
     * Cancelled purchase orders don't count. Null when there are none left.
     *
     * @param iterable<PurchaseOrder> $purchaseOrders all of one order's
     */
    public static function of(iterable $purchaseOrders): ?self
    {
        $fulfilments = [];
        foreach ($purchaseOrders as $purchaseOrder) {
            if (PurchaseOrderState::CANCELLED !== $purchaseOrder->getState()) {
                $fulfilments[$purchaseOrder->getFulfilment()->value] = true;
            }
        }

        return match (true) {
            [] === $fulfilments => null,
            isset($fulfilments[Fulfilment::Unconfirmed->value]) => self::Unconfirmed,
            isset($fulfilments[Fulfilment::OrsDirect->value], $fulfilments[Fulfilment::CrossDock->value]) => self::Mixed,
            isset($fulfilments[Fulfilment::CrossDock->value]) => self::CrossDock,
            default => self::Direct,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::CrossDock => 'Cross-dock',
            self::Mixed => 'Mixed',
            self::Unconfirmed => 'Not confirmed',
        };
    }

    /** Tabler badge class, as on Sylius's own order list. */
    public function badge(): string
    {
        return match ($this) {
            self::Direct => 'bg-green-lt',
            self::CrossDock => 'bg-blue-lt',
            self::Mixed => 'bg-yellow-lt',
            self::Unconfirmed => 'bg-red-lt',
        };
    }
}
