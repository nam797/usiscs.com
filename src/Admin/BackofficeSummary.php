<?php

declare(strict_types=1);

namespace App\Admin;

use App\Distributor\PurchaseOrderState;
use App\Entity\Contact\ContactMessage;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Rfq\RfqRequest;
use App\Repository\Contact\ContactMessageRepository;
use App\Repository\Procurement\PurchaseOrderRepository;
use App\Repository\Rfq\RfqRequestRepository;

/**
 * What the backoffice dashboard (/backoffice) shows: what's due at the
 * warehouse, what's there waiting to ship on, purchase orders staff must
 * act on, and new leads.
 */
final class BackofficeSummary
{
    /** How many rows each dashboard list shows; the cards link to the full list. */
    public const LIST_LIMIT = 10;

    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly RfqRequestRepository $rfqs,
        private readonly ContactMessageRepository $contactMessages,
    ) {
    }

    /**
     * @return array{
     *     today: \DateTimeImmutable,
     *     weekEnd: \DateTimeImmutable,
     *     warehouse: array<string, int>,
     *     dueThisWeek: int,
     *     comingIn: list<PurchaseOrder>,
     *     toShipOn: list<PurchaseOrder>,
     *     purchaseOrders: array<string, int>,
     *     newRfqs: int,
     *     newContactMessages: int,
     * }
     */
    public function build(\DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $weekEnd = $today->modify('+6 days');

        return [
            'today' => $today,
            'weekEnd' => $weekEnd,
            'warehouse' => $this->purchaseOrders->countByWarehouseStatus($today),
            'dueThisWeek' => $this->purchaseOrders->countDueBetween($today, $weekEnd),
            'comingIn' => $this->purchaseOrders->findComingIn(self::LIST_LIMIT),
            'toShipOn' => $this->purchaseOrders->findReceivedNotShippedOn(self::LIST_LIMIT),
            'purchaseOrders' => $this->purchaseOrders->countInStates([PurchaseOrderState::NEEDS_ATTENTION, PurchaseOrderState::AWAITING_MANUAL_ENTRY, PurchaseOrderState::ON_HOLD]),
            'newRfqs' => $this->rfqs->count(['status' => RfqRequest::STATUS_NEW]),
            'newContactMessages' => $this->contactMessages->count(['status' => ContactMessage::STATUS_NEW]),
        ];
    }

}
