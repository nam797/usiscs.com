<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\PurchaseOrder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A distributor we pass paid orders on to (ORS Nasco, later others). One
 * adapter per distributor; DistributorRegistry finds it by code, which is
 * what ProductVariant::$distributor and PurchaseOrder::$distributor hold.
 *
 * Adding a distributor = one class implementing this (it's picked up
 * automatically) plus its credentials in .env.local / Upsun variables.
 *
 * Adapters only talk to the distributor: they never change the purchase
 * order's state or flush, the caller does (PurchaseOrderSubmitter).
 */
#[AutoconfigureTag(self::TAG)]
interface DistributorInterface
{
    public const TAG = 'app.distributor';

    /** e.g. "ors_nasco"; stored on variants and purchase orders, so never change it. */
    public function getCode(): string;

    public function getName(): string;

    /** False while orders must be entered by hand (no order API yet): submit() is then never called. */
    public function canSubmit(): bool;

    /**
     * Places the order. Use the purchase order number as the distributor's
     * customer PO number, so findExisting() can find it again.
     *
     * @throws DistributorUnavailableException down, timed out, 5xx: worth retrying
     * @throws OrderRejectedException          refused for a reason retrying won't fix
     */
    public function submit(PurchaseOrder $purchaseOrder): SubmissionResult;

    /**
     * The distributor's order number if it already has this purchase order
     * (an earlier attempt that timed out but went through); null if it
     * doesn't, or if it can't tell. Asked before every retry so an order is
     * never placed twice.
     *
     * @throws DistributorUnavailableException
     */
    public function findExisting(PurchaseOrder $purchaseOrder): ?string;
}
