<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;

/**
 * Purchase orders that have sat in one state for too long. Staff get one
 * email listing them (app:distributor:sync-orders), at most once a day per
 * purchase order. Retries already end in "needs attention" with their own
 * email, so queued and sending orders aren't watched here.
 */
final class PurchaseOrderWatchdog
{
    /** state => [how long is too long, why it matters] */
    public const LIMITS = [
        PurchaseOrderState::AWAITING_MANUAL_ENTRY => ['4 hours', 'not placed with the distributor yet'],
        PurchaseOrderState::NEEDS_ATTENTION => ['1 day', 'still needs attention'],
        PurchaseOrderState::SUBMITTED => ['1 day', 'the distributor hasn\'t acknowledged it'],
        PurchaseOrderState::ON_HOLD => ['1 day', 'on hold at the distributor'],
        PurchaseOrderState::ACKNOWLEDGED => ['5 days', 'not shipped yet'],
        PurchaseOrderState::PARTIALLY_SHIPPED => ['7 days', 'the rest hasn\'t shipped'],
    ];

    private const REMIND_AFTER = '-1 day';

    /**
     * @param iterable<PurchaseOrder> $purchaseOrders
     *
     * @return list<array{purchaseOrder: PurchaseOrder, reason: string}> those due a reminder
     */
    public function stuck(iterable $purchaseOrders, \DateTimeImmutable $now): array
    {
        $stuck = [];
        foreach ($purchaseOrders as $purchaseOrder) {
            $limit = self::LIMITS[$purchaseOrder->getState()] ?? null;
            if (null === $limit || $purchaseOrder->getStateChangedAt() > $now->modify('-'.$limit[0])) {
                continue;
            }
            if (null !== $purchaseOrder->getAlertedAt() && $purchaseOrder->getAlertedAt() > $now->modify(self::REMIND_AFTER)) {
                continue;
            }

            $stuck[] = [
                'purchaseOrder' => $purchaseOrder,
                'reason' => sprintf('%s for %s: %s', PurchaseOrderState::label($purchaseOrder->getState()), self::age($purchaseOrder->getStateChangedAt(), $now), $limit[1]),
            ];
        }

        return $stuck;
    }

    private static function age(\DateTimeImmutable $since, \DateTimeImmutable $now): string
    {
        $hours = intdiv(max(0, $now->getTimestamp() - $since->getTimestamp()), 3600);

        return $hours < 48 ? sprintf('%d hours', $hours) : sprintf('%d days', intdiv($hours, 24));
    }
}
