<?php

declare(strict_types=1);

namespace App\Form\Extension;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Offer package protection" on the admin channel form, in the money
 * section (config/packages/twig_hooks.yaml). The prices are the tiers in
 * the backoffice (PackageProtectionTierCrudController).
 */
final class ChannelPackageProtectionExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [ChannelType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('packageProtectionEnabled', CheckboxType::class, [
            'label' => 'Offer package protection at checkout',
            'required' => false,
            'help' => 'Ticked for the customer by default. Prices are set in the backoffice, under Package protection.',
        ]);
    }
}
