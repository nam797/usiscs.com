<?php

declare(strict_types=1);

namespace App\Shipping\PackageProtection;

use App\Entity\Channel\Channel;
use App\Entity\Order\Order;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Adds the package protection fee to the order as an order adjustment,
 * priced by the cart's items total (TierPicker).
 *
 * Priority 15: after promotions (20), so discounts lower the items total,
 * and before taxes (10) and the payment amount (0), so the customer is
 * charged for it. Sylius's adjustment clearer doesn't know this type, so
 * the processor removes its own adjustment before working it out again.
 *
 * Charged when the channel offers it, a tier matches, the customer hasn't
 * unticked it, and checkout is past the cart: it first shows up with the
 * shipping choice, where the box is.
 */
#[AutoconfigureTag('sylius.order_processor', ['priority' => 15])]
final class PackageProtectionOrderProcessor implements OrderProcessorInterface
{
    public const ADJUSTMENT_TYPE = 'package_protection';
    public const LABEL = 'Package protection';

    /** @param FactoryInterface<AdjustmentInterface> $adjustmentFactory */
    public function __construct(
        #[Autowire(service: 'sylius.factory.adjustment')]
        private readonly FactoryInterface $adjustmentFactory,
        private readonly TierPicker $tiers,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        if (!$order instanceof Order || !$order->canBeProcessed()) {
            return;
        }

        $order->removeAdjustments(self::ADJUSTMENT_TYPE);

        $fee = $this->offeredFee($order);
        if (null === $fee || 0 === $fee || !$order->wantsPackageProtection() || OrderCheckoutStates::STATE_CART === $order->getCheckoutState()) {
            return;
        }

        $adjustment = $this->adjustmentFactory->createNew();
        $adjustment->setType(self::ADJUSTMENT_TYPE);
        $adjustment->setLabel(self::LABEL);
        $adjustment->setAmount($fee);
        $adjustment->setNeutral(false);
        $order->addAdjustment($adjustment);
    }

    /** @return int|null the fee this order would pay, in cents; null when protection isn't offered for it */
    public function offeredFee(Order $order): ?int
    {
        $channel = $order->getChannel();
        if (!$channel instanceof Channel || !$channel->isPackageProtectionEnabled()) {
            return null;
        }

        return $this->tiers->feeFor($order->getItemsTotal());
    }
}
