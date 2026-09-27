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
 */
class RfqLineItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantity', IntegerType::class, ['label' => 'Qty'])
            ->add('partNumber', TextType::class, ['label' => 'Part / Item #', 'required' => false])
            ->add('description', TextType::class, ['label' => 'Description'])
            ->add('unitOfMeasure', TextType::class, ['label' => 'UOM', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RfqLineItem::class]);
    }
}
