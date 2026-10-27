<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorRegistry;
use Sylius\Bundle\ProductBundle\Form\Type\ProductVariantType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Distributor, fulfilment and shipping note on the admin variant form (and the variant
 * part of a simple product's form). Shown in the shipping section, see
 * config/packages/twig_hooks.yaml.
 */
final class ProductVariantFulfilmentExtension extends AbstractTypeExtension
{
    public function __construct(private readonly DistributorRegistry $distributors)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        return [ProductVariantType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('distributor', ChoiceType::class, [
                'label' => 'Distributor',
                'choices' => $this->distributors->choices(),
                'required' => false,
                'placeholder' => 'Not set',
                'help' => 'Who we order it from when it sells. Orders for SKUs without one wait for staff.',
            ])
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
