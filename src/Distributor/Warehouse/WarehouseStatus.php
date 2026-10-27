<?php

declare(strict_types=1);

namespace App\Distributor\Warehouse;

use App\Distributor\PurchaseOrderState;
use App\Entity\Procurement\PurchaseOrder;
use Doctrine\ORM\QueryBuilder;

/**
 * Where a cross-dock purchase order is from our warehouse's point of view.
 * Not stored: worked out from the distributor's state and the warehouse
 * fields, the same way in PHP (of()) and in DQL (apply(), for the
 * backoffice filter and dashboard), so the badge and the filter always agree.
 */
enum WarehouseStatus: string
{
    /** The distributor hasn't shipped it to us yet. */
    case AwaitingDistributor = 'awaiting_distributor';
    case InTransit = 'in_transit';
    /** Not received, and the expected date has passed. */
    case Overdue = 'overdue';
    /** Checked in; still to ship on to the customer. */
    case Received = 'received';
    case ShippedToCustomer = 'shipped_to_customer';

    /** Distributor states meaning at least part of it is on its way to us. */
    public const DISTRIBUTOR_SHIPPED = [PurchaseOrderState::PARTIALLY_SHIPPED, PurchaseOrderState::SHIPPED, PurchaseOrderState::INVOICED];

    /** Null for direct and cancelled purchase orders: nothing comes through the warehouse. */
    public static function of(PurchaseOrder $purchaseOrder, \DateTimeImmutable $today): ?self
    {
        if (!$purchaseOrder->isCrossDock() || PurchaseOrderState::CANCELLED === $purchaseOrder->getState()) {
            return null;
        }
        if (null !== $purchaseOrder->getForwardedAt()) {
            return self::ShippedToCustomer;
        }
        if (null !== $purchaseOrder->getReceivedAt()) {
            return self::Received;
        }
        $expectedAt = $purchaseOrder->getExpectedAt();
        if (null !== $expectedAt && $expectedAt->format('Y-m-d') < $today->format('Y-m-d')) {
            return self::Overdue;
        }

        return in_array($purchaseOrder->getState(), self::DISTRIBUTOR_SHIPPED, true) ? self::InTransit : self::AwaitingDistributor;
    }

    /**
     * Narrows a purchase order query (alias $alias) to this status: the same
     * rule as of(), in DQL. $negate keeps everything else instead. $prefix
     * keeps its parameter names apart from the query's others.
     */
    public function apply(QueryBuilder $query, string $alias, string $prefix, \DateTimeImmutable $today, bool $negate = false): QueryBuilder
    {
        $condition = $this->condition($alias, $prefix);
        $query->andWhere($negate ? sprintf('NOT (%s)', $condition) : $condition);
        $parameters = [$prefix.'today' => $today->setTime(0, 0), $prefix.'shipped' => self::DISTRIBUTOR_SHIPPED];
        foreach ($parameters as $name => $value) {
            // Doctrine refuses parameters the query doesn't use.
            if (str_contains($condition, ':'.$name)) {
                $query->setParameter($name, $value);
            }
        }

        return $query;
    }

    private function condition(string $alias, string $prefix): string
    {
        $open = sprintf('%1$s.forwardedAt IS NULL AND %1$s.receivedAt IS NULL', $alias);
        $notLate = sprintf('(%1$s.expectedAt IS NULL OR %1$s.expectedAt >= :%2$stoday)', $alias, $prefix);

        return match ($this) {
            self::ShippedToCustomer => sprintf('%s.forwardedAt IS NOT NULL', $alias),
            self::Received => sprintf('%1$s.forwardedAt IS NULL AND %1$s.receivedAt IS NOT NULL', $alias),
            self::Overdue => sprintf('%s AND %s.expectedAt < :%stoday', $open, $alias, $prefix),
            self::InTransit => sprintf('%s AND %s AND %s.state IN (:%sshipped)', $open, $notLate, $alias, $prefix),
            self::AwaitingDistributor => sprintf('%s AND %s AND %s.state NOT IN (:%sshipped)', $open, $notLate, $alias, $prefix),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingDistributor => 'Not shipped by distributor yet',
            self::InTransit => 'On its way to us',
            self::Overdue => 'Overdue',
            self::Received => 'Received, to ship on',
            self::ShippedToCustomer => 'Shipped to customer',
        };
    }

    /** Bootstrap badge colour. */
    public function badge(): string
    {
        return match ($this) {
            self::AwaitingDistributor => 'secondary',
            self::InTransit => 'info',
            self::Overdue => 'danger',
            self::Received => 'warning',
            self::ShippedToCustomer => 'success',
        };
    }

    /** @return array<string, string> label => value, for choice lists */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
