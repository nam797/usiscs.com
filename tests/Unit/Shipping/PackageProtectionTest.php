<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping;

use App\Entity\Channel\Channel;
use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Order\OrderItemUnit;
use App\Entity\Shipping\PackageProtectionTier;
use App\Repository\Shipping\PackageProtectionTierRepository;
use App\Shipping\PackageProtection\PackageProtectionOrderProcessor;
use App\Shipping\PackageProtection\TierPicker;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Resource\Factory\FactoryInterface;

final class PackageProtectionTest extends TestCase
{
    public function testTheHighestTierTheCartReachesWins(): void
    {
        $tiers = [new PackageProtectionTier(25000, 795), new PackageProtectionTier(0, 295), new PackageProtectionTier(10000, 495)];

        self::assertSame(295, TierPicker::pick($tiers, 9999)?->getFee());
        self::assertSame(495, TierPicker::pick($tiers, 10000)?->getFee(), 'a tier starts at its own amount');
        self::assertSame(795, TierPicker::pick($tiers, 1000000)?->getFee());
        self::assertNull(TierPicker::pick([new PackageProtectionTier(5000, 295)], 4999), 'below the lowest tier: not offered');
        self::assertNull(TierPicker::pick([], 4999));
    }

    public function testTheFeeIsAddedOnceCheckoutIsPastTheCart(): void
    {
        $order = $this->order(12000, OrderCheckoutStates::STATE_ADDRESSED);

        $this->processor()->process($order);
        $this->processor()->process($order);

        self::assertSame(495, $order->getPackageProtectionTotal(), 'worked out again, not added twice');
        self::assertSame(12000 + 495, $order->getTotal());
    }

    public function testNoFeeWhenUntickedSwitchedOffOrStillInTheCart(): void
    {
        $unticked = $this->order(12000, OrderCheckoutStates::STATE_SHIPPING_SELECTED);
        $this->processor()->process($unticked);
        self::assertSame(495, $unticked->getPackageProtectionTotal());
        $unticked->setPackageProtection(false);
        $this->processor()->process($unticked);
        self::assertSame(0, $unticked->getPackageProtectionTotal(), 'removed when the customer unticks it');

        $off = $this->order(12000, OrderCheckoutStates::STATE_ADDRESSED, enabled: false);
        $this->processor()->process($off);
        self::assertSame(0, $off->getPackageProtectionTotal());

        $cart = $this->order(12000, OrderCheckoutStates::STATE_CART);
        $this->processor()->process($cart);
        self::assertSame(0, $cart->getPackageProtectionTotal());
    }

    private function processor(): PackageProtectionOrderProcessor
    {
        $repository = $this->createStub(PackageProtectionTierRepository::class);
        $repository->method('findAllInOrder')->willReturn([new PackageProtectionTier(0, 295), new PackageProtectionTier(10000, 495)]);

        /** @var FactoryInterface<AdjustmentInterface>&Stub $adjustments */
        $adjustments = $this->createStub(FactoryInterface::class);
        $adjustments->method('createNew')->willReturnCallback(static fn (): Adjustment => new Adjustment());

        return new PackageProtectionOrderProcessor($adjustments, new TierPicker($repository));
    }

    private function order(int $itemsTotal, string $checkoutState, bool $enabled = true): Order
    {
        $channel = new Channel();
        $channel->setPackageProtectionEnabled($enabled);
        $order = new Order();
        $order->setChannel($channel);
        $order->setCheckoutState($checkoutState);
        $item = new OrderItem();
        $item->setUnitPrice($itemsTotal);
        new OrderItemUnit($item);
        $order->addItem($item);

        return $order;
    }
}
