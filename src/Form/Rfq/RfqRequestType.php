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
 *
 * `empty_data` is set explicitly to '' on fullName/company/email/phone:
 * without it, Symfony's default empty-data handling for a non-compound
 * field binds a blank submission as `null` rather than ''. Fine for this
 * form's nullable fields (jobSite, deliveryAddress, additionalNotes),
 * but RfqRequest's fullName/company/email/phone are non-nullable
 * `string` properties -- without this option, submitting the form with
 * any of those blank (easy from a bot/curl bypassing the browser's own
 * `required` attribute, or just unlucky timing) 500s with "Expected
 * argument of type string, null given" instead of a normal validation
 * error. Reproduced and confirmed during the security pass (2026-10-22).
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
            ->add('fullName', TextType::class, ['label' => 'Full Name', 'empty_data' => ''])
            ->add('company', TextType::class, ['label' => 'Company', 'empty_data' => ''])
            ->add('email', EmailType::class, ['label' => 'Email', 'empty_data' => ''])
            ->add('phone', TelType::class, ['label' => 'Phone', 'empty_data' => ''])

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
                // CollectionType bubbles errors to the parent by default, but
                // the template renders "Add at least one item." from
                // form.lineItems' own errors -- without this it never shows.
                'error_bubbling' => false,
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
