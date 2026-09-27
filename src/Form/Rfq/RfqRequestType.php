<?php

declare(strict_types=1);

namespace App\Form\Rfq;

use App\Entity\Rfq\RfqRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The public /quote form — field order and grouping match the RFQ Flow
 * Spec doc (itself based on the current usiscs.com /quote page):
 * Customer Info, Project Details, Items Needed, Additional Info.
 */
class RfqRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerType', ChoiceType::class, [
                'label' => 'Customer Type',
                'choices' => [
                    'New Customer' => RfqRequest::CUSTOMER_TYPE_NEW,
                    'Existing Customer' => RfqRequest::CUSTOMER_TYPE_EXISTING,
                ],
                'expanded' => false,
            ])
            ->add('fullName', TextType::class, ['label' => 'Full Name'])
            ->add('company', TextType::class, ['label' => 'Company'])
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('phone', TelType::class, ['label' => 'Phone'])

            ->add('jobSite', TextType::class, [
                'label' => 'Job Site / Facility Name',
                'required' => false,
            ])
            ->add('requiredByDate', DateType::class, [
                'label' => 'Required By Date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('urgencyLevel', ChoiceType::class, [
                'label' => 'Urgency Level',
                'choices' => [
                    'Standard' => RfqRequest::URGENCY_STANDARD,
                    'Rush' => RfqRequest::URGENCY_RUSH,
                    'Emergency' => RfqRequest::URGENCY_EMERGENCY,
                ],
                'expanded' => false,
            ])
            ->add('deliveryAddress', TextType::class, [
                'label' => 'Delivery Address',
                'required' => false,
            ])

            ->add('lineItems', CollectionType::class, [
                'label' => false,
                'entry_type' => RfqLineItemType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])

            ->add('additionalNotes', TextareaType::class, [
                'label' => 'Additional Notes / Special Requirements',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RfqRequest::class]);
    }
}
