<?php

declare(strict_types=1);

namespace App\Distributor\Warehouse;

/**
 * When a cross-dock delivery should reach our warehouse. The distributor
 * gives no delivery date, only tracking, so it's the ship date plus a
 * number of business days (app.cross_dock_transit_days); staff can change it.
 */
final class ExpectedArrival
{
    public static function after(\DateTimeImmutable $shippedAt, int $businessDays): \DateTimeImmutable
    {
        $date = $shippedAt->setTime(0, 0);
        for ($added = 0; $added < max(0, $businessDays);) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                ++$added;
            }
        }

        return $date;
    }
}
