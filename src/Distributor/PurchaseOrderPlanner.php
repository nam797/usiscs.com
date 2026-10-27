<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderLine;
use App\Entity\Product\ProductVariant;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Splits a paid order into purchase orders: one per distributor and
 * fulfilment, since a direct order ships to the customer and a cross-dock
 * one to our warehouse. Numbered "{order number}-1", "-2"... in a stable
 * order, so the same order always gets the same numbers.
 *
 * Nothing is guessed: items with no distributor, a distributor we have no
 * adapter for, or an unconfirmed fulfilment still get a purchase order, and
 * the worker sends it to "needs attention" (see problems()) and emails staff.
 */
final class PurchaseOrderPlanner
{
    public function __construct(
        private readonly DistributorRegistry $distributors,
        private readonly SupplierSkuLookupInterface $supplierSkus,
    ) {
    }

    /** @return list<PurchaseOrder> new, not persisted */
    public function plan(OrderInterface $order): array
    {
        /** @var array<string, array{distributor: ?string, fulfilment: Fulfilment, items: list<\Sylius\Component\Core\Model\OrderItemInterface>}> $groups */
        $groups = [];
        $variantCodes = [];
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            $distributor = $variant instanceof ProductVariant ? $variant->getDistributor() : null;
            $fulfilment = $variant instanceof ProductVariant ? $variant->getFulfilment() : Fulfilment::Unconfirmed;
            $key = ($distributor ?? '~').'|'.$fulfilment->value;

            $groups[$key] ??= ['distributor' => $distributor, 'fulfilment' => $fulfilment, 'items' => []];
            $groups[$key]['items'][] = $item;
            if (null !== $variant?->getCode()) {
                $variantCodes[] = $variant->getCode();
            }
        }
        ksort($groups);

        $supplierSkus = $this->supplierSkus->find(array_values(array_unique($variantCodes)));
        $orderNumber = $order->getNumber() ?? (string) $order->getId();

        $purchaseOrders = [];
        foreach (array_values($groups) as $index => $group) {
            $purchaseOrder = new PurchaseOrder($order, sprintf('%s-%d', $orderNumber, $index + 1), $group['distributor'] ?? PurchaseOrder::UNASSIGNED, $group['fulfilment']);

            foreach ($group['items'] as $item) {
                $variant = $item->getVariant();
                $code = (string) $variant?->getCode();
                $packUnit = $variant instanceof ProductVariant ? $variant->getPackUnit() : null;

                new PurchaseOrderLine(
                    $purchaseOrder,
                    $item,
                    $code,
                    $supplierSkus[$code]['supplierSku'] ?? $code,
                    self::nameOf($item->getProductName(), $item->getVariantName()),
                    $item->getQuantity(),
                    $packUnit?->sellUnit,
                    $item->getDiscountedUnitPrice(),
                    $supplierSkus[$code]['unitCostInCents'] ?? null,
                );
            }

            $purchaseOrder->log(PurchaseOrderEventType::Created, sprintf(
                '%d line(s) for %s, %s',
                $purchaseOrder->getLines()->count(),
                $this->distributors->nameOf($purchaseOrder->getDistributor()),
                $group['fulfilment']->label(),
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
            $problems[] = sprintf('No distributor is set for %s: set one on the purchase order (and on the variant for next time).', $skus);
        } elseif (null === $this->distributors->get($purchaseOrder->getDistributor())) {
            $problems[] = sprintf('There is no distributor called "%s".', $purchaseOrder->getDistributor());
        }
        if (Fulfilment::Unconfirmed === $purchaseOrder->getFulfilment()) {
            $problems[] = sprintf('Fulfilment is not confirmed for %s: choose direct or cross-dock.', $skus);
        }

        return $problems;
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
