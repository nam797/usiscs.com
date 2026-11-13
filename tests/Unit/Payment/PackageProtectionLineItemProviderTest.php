<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Payment\Stripe\PackageProtectionLineItemProvider;
use App\Shipping\PackageProtection\PackageProtectionOrderProcessor;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class PackageProtectionLineItemProviderTest extends TestCase
{
    public function testItAddsALineItemAfterTheOrdersOwn(): void
    {
        $params = ['line_items' => [['quantity' => 2, 'price_data' => ['unit_amount' => 1299]]]];

        (new PackageProtectionLineItemProvider())->provide($this->paymentRequest(495), $params);

        $lineItems = $params['line_items'];
        self::assertIsArray($lineItems);
        self::assertCount(2, $lineItems);
        self::assertSame([
            'price_data' => ['currency' => 'USD', 'unit_amount' => 495, 'product_data' => ['name' => 'Package protection']],
            'quantity' => 1,
        ], $lineItems[1]);
    }

    public function testNothingWithoutProtection(): void
    {
        $params = ['line_items' => [['quantity' => 1]]];

        (new PackageProtectionLineItemProvider())->provide($this->paymentRequest(0), $params);

        self::assertIsArray($params['line_items']);
        self::assertCount(1, $params['line_items']);
    }

    private function paymentRequest(int $fee): PaymentRequestInterface
    {
        $order = new Order();
        if (0 !== $fee) {
            $adjustment = new Adjustment();
            $adjustment->setType(PackageProtectionOrderProcessor::ADJUSTMENT_TYPE);
            $adjustment->setAmount($fee);
            $order->addAdjustment($adjustment);
        }
        $payment = new Payment();
        $payment->setCurrencyCode('USD');
        $order->addPayment($payment);
        $paymentRequest = $this->createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);

        return $paymentRequest;
    }
}
