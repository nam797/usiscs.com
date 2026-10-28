<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderLine;
use App\Entity\Product\ProductVariant;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Splits a paid order into purchase orders: one per distributor and
 * fulfilment, since a direct order ships to the customer and a cross-dock
 * one to our warehouse. Numbered "{order number}-1", "-2"... in a stable
 * order, so the same order always gets the same numbers.
 *
 * Each line goes to the source SourceSelector picks (the cheapest with
 * stock, never at a loss), with that distributor's SKU and cost; why is on
 * the purchase order's timeline.
 *
 * Nothing is guessed: a line with no usable source still gets a purchase
 * order, and the worker sends it to "needs attention" (see problems()) and
 * emails staff. When the SKU has a single source that only lacks a
 * confirmed fulfilment, that distributor is kept so staff just set it.
 */
final class PurchaseOrderPlanner
{
    public function __construct(
        private readonly DistributorRegistry $distributors,
        private readonly SourceSelector $sources,
    ) {
    }

    /** @return list<PurchaseOrder> new, not persisted */
    public function plan(OrderInterface $order): array
    {
        /** @var array<string, array{distributor: string, fulfilment: Fulfilment, lines: list<array{item: OrderItemInterface, source: ?SupplierItem, reason: string}>}> $groups */
        $groups = [];
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            $choice = $variant instanceof ProductVariant
                ? $this->sources->choose($variant, $item->getQuantity(), $item->getDiscountedUnitPrice())
                : new SourceChoice(null, 'no product variant');

            [$distributor, $fulfilment] = null !== $choice->source
                ? [$choice->source->getSupplier(), $choice->source->getFulfilment()]
                : [self::onlyUnconfirmedSource($variant)?->getSupplier() ?? PurchaseOrder::UNASSIGNED, Fulfilment::Unconfirmed];
            $key = $distributor.'|'.$fulfilment->value;

            $groups[$key] ??= ['distributor' => $distributor, 'fulfilment' => $fulfilment, 'lines' => []];
            $groups[$key]['lines'][] = ['item' => $item, 'source' => $choice->source, 'reason' => $choice->reason];
        }
        ksort($groups);

        $orderNumber = $order->getNumber() ?? (string) $order->getId();

        $purchaseOrders = [];
        foreach (array_values($groups) as $index => $group) {
            $purchaseOrder = new PurchaseOrder($order, sprintf('%s-%d', $orderNumber, $index + 1), $group['distributor'], $group['fulfilment']);

            $reasons = [];
            foreach ($group['lines'] as ['item' => $item, 'source' => $source, 'reason' => $reason]) {
                $variant = $item->getVariant();
                $code = (string) $variant?->getCode();
                $packUnit = $variant instanceof ProductVariant ? $variant->getPackUnit() : null;
                // No usable source: the one that will be fixed, if there's just one.
                $source ??= $variant instanceof ProductVariant ? $variant->getSource($group['distributor']) : null;

                new PurchaseOrderLine(
                    $purchaseOrder,
                    $item,
                    $code,
                    $source?->getSupplierSku() ?? $code,
                    self::nameOf($item->getProductName(), $item->getVariantName()),
                    $item->getQuantity(),
                    $packUnit?->sellUnit,
                    $item->getDiscountedUnitPrice(),
                    $source?->getCostInCents(),
                );
                $reasons[] = sprintf('%s: %s', $code, $reason);
            }

            $purchaseOrder->log(PurchaseOrderEventType::Created, sprintf(
                "%d line(s) for %s, %s.\n%s",
                $purchaseOrder->getLines()->count(),
                $this->distributors->nameOf($purchaseOrder->getDistributor()),
                $group['fulfilment']->label(),
                implode("\n", $reasons),
            ));

            $purchaseOrders[] = $purchaseOrder;
        }

        return $purchaseOrders;
    }

    /**
     * Why this purchase order can't be sent as it is; empty when it can.
     * Checked again before every send, since staff may fix things.
     *
     * @return list<string>
     */
    public function problems(PurchaseOrder $purchaseOrder): array
    {
        $skus = implode(', ', $purchaseOrder->getLines()->map(static fn (PurchaseOrderLine $line): string => $line->getVariantCode())->toArray());

        $problems = [];
        if (PurchaseOrder::UNASSIGNED === $purchaseOrder->getDistributor()) {
            $problems[] = sprintf('No distributor is set for %s: set one on the purchase order (and add a source for next time).', $skus);
        } elseif (null === $this->distributors->get($purchaseOrder->getDistributor())) {
            $problems[] = sprintf('There is no distributor called "%s".', $purchaseOrder->getDistributor());
        }
        if (Fulfilment::Unconfirmed === $purchaseOrder->getFulfilment()) {
            $problems[] = sprintf('Fulfilment is not confirmed for %s: choose direct or cross-dock.', $skus);
        }

        return $problems;
    }

    /** The SKU's one switched-on source, when its only problem is an unconfirmed fulfilment. */
    private static function onlyUnconfirmedSource(mixed $variant): ?SupplierItem
    {
        if (!$variant instanceof ProductVariant) {
            return null;
        }
        $enabled = array_values(array_filter($variant->getSources()->toArray(), static fn (SupplierItem $s): bool => $s->isEnabled()));

        return 1 === count($enabled) && Fulfilment::Unconfirmed === $enabled[0]->getFulfilment() ? $enabled[0] : null;
    }

    private static function nameOf(?string $product, ?string $variant): string
    {
        $name = trim((string) $product);
        if (null !== $variant && '' !== trim($variant) && trim($variant) !== $name) {
            $name .= ' - '.trim($variant);
        }

        return mb_substr('' === $name ? '(no name)' : $name, 0, 255);
    }
}
