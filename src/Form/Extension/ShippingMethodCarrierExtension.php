<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Shipping\Carrier;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Carrier on the admin shipping method form, next to the zone (see
 * config/packages/twig_hooks.yaml). The fee itself is Sylius's own
 * calculator setting further down the same form.
 */
final class ShippingMethodCarrierExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [ShippingMethodType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('carrier', EnumType::class, [
                'class' => Carrier::class,
                'choice_label' => static fn (Carrier $carrier): string => $carrier->label(),
                'label' => 'Carrier',
                'required' => false,
                'placeholder' => 'None',
                'help' => 'Pre-picked when the warehouse ships an order on, and shown to staff placing ORS orders.',
            ]);
    }
}
