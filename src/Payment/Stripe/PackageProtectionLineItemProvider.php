<?php

declare(strict_types=1);

namespace App\Payment\Stripe;

use App\Entity\Order\Order;
use FluxSE\SyliusStripePlugin\Provider\InnerParamsProviderInterface;
use Stripe\Checkout\Session;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Package protection as a line item on the Stripe Checkout page. The plugin
 * only sends the order's items and shipping (its LineItemsProvider, priority
 * -600), so without this Stripe would charge less than the order total.
 *
 * @implements InnerParamsProviderInterface<Session>
 */
#[AutoconfigureTag('flux_se.sylius_stripe.provider.checkout.create.inner_params', ['priority' => -650])]
final class PackageProtectionLineItemProvider implements InnerParamsProviderInterface
{
    public const NAME = 'Package protection';

    public function provide(PaymentRequestInterface $paymentRequest, array &$params): void
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            return;
        }
        $order = $payment->getOrder();
        $currencyCode = $payment->getCurrencyCode();
        if (!$order instanceof Order || null === $currencyCode || $order->getPackageProtectionTotal() <= 0) {
            return;
        }

        $lineItems = $params['line_items'] ?? [];
        \assert(\is_array($lineItems));
        $lineItems[] = [
            'price_data' => [
                'currency' => $currencyCode,
                'unit_amount' => $order->getPackageProtectionTotal(),
                'product_data' => ['name' => self::NAME],
            ],
            'quantity' => 1,
        ];
        $params['line_items'] = $lineItems;
    }
}
