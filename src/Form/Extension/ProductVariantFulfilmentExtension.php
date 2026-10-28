<?php

declare(strict_types=1);

namespace App\Form\Extension;

use Sylius\Bundle\ProductBundle\Form\Type\ProductVariantType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Shipping note on the admin variant form (and the variant part of a simple
 * product's form), shown in the shipping section with the SKU's sources
 * (distributor, fulfilment, cost, stock), which are edited in the
 * backoffice. See config/packages/twig_hooks.yaml.
 */
final class ProductVariantFulfilmentExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [ProductVariantType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shippingNote', TextType::class, [
                'label' => 'Shipping note',
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'e.g. "Aerosol: check carrier and hazmat rules".',
            ]);
    }
}
