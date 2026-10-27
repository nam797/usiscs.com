<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Catalog\Fulfilment\Fulfilment;
use Sylius\Bundle\ProductBundle\Form\Type\ProductVariantType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Fulfilment and shipping note on the admin variant form (and the variant
 * part of a simple product's form). Shown in the shipping section, see
 * config/packages/twig_hooks.yaml.
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
            ->add('fulfilment', EnumType::class, [
                'class' => Fulfilment::class,
                'label' => 'Fulfilment',
                'choice_label' => static fn (Fulfilment $fulfilment): string => $fulfilment->label(),
                'help' => 'ORS direct: ORS ships to the customer. Cross-dock: ORS ships to our warehouse and we ship on. Products can\'t go live while any SKU is not confirmed.',
            ])
            ->add('shippingNote', TextType::class, [
                'label' => 'Shipping note',
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'e.g. "Aerosol: check carrier and hazmat rules".',
            ]);
    }
}
