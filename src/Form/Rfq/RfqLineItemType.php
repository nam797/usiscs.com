<?php

declare(strict_types=1);

namespace App\Form\Rfq;

use App\Entity\Rfq\RfqLineItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Backs the inline "Items Needed" line-item table (Qty / Part#/Item# /
 * Description / UOM) inside RfqRequestCrudController's CollectionField.
 *
 * `description`'s `empty_data` is set explicitly for the same reason as
 * RfqRequestType's required text fields: it's a non-nullable `string`
 * property on RfqLineItem, and without this a blank submission 500s
 * instead of failing its NotBlank validation cleanly. `quantity`'s own
 * `empty_data` ('1', matching the entity's own default) fixes the same
 * class of bug for its non-nullable `int` property -- IntegerType has
 * no built-in null-to-empty-data coercion the way TextType does, but
 * explicitly setting `empty_data` here was confirmed (2026-10-22) to
 * still take effect and avoid the 500.
 */
class RfqLineItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantity', IntegerType::class, ['label' => 'Qty', 'empty_data' => '1'])
            ->add('partNumber', TextType::class, ['label' => 'Part / Item #', 'required' => false])
            ->add('description', TextType::class, ['label' => 'Description', 'empty_data' => ''])
            ->add('unitOfMeasure', TextType::class, ['label' => 'UOM', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RfqLineItem::class]);
    }
}
