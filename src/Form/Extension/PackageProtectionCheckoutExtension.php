<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Entity\Order\Order;
use App\Shipping\PackageProtection\PackageProtectionOrderProcessor;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\SelectShippingType;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * The package protection box on the checkout shipping step, ticked until
 * the customer unticks it (Order::getPackageProtection) and labelled with
 * the fee for their cart. Only there when the channel offers it and a tier
 * matches. Rendered under the shipping methods (config/packages/twig_hooks.yaml).
 */
final class PackageProtectionCheckoutExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly PackageProtectionOrderProcessor $packageProtection,
        private readonly MoneyFormatterInterface $moneyFormatter,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [SelectShippingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $order = $event->getData();
            if (!$order instanceof Order) {
                return;
            }
            $fee = $this->packageProtection->offeredFee($order);
            if (null === $fee || 0 === $fee) {
                return;
            }

            $event->getForm()->add('packageProtection', CheckboxType::class, [
                'required' => false,
                'label' => sprintf(
                    'Protect my package against loss, damage or theft (+%s)',
                    $this->moneyFormatter->format($fee, (string) ($order->getCurrencyCode() ?? 'USD'), $order->getLocaleCode()),
                ),
            ]);
        });
    }
}
