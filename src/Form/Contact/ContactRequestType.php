<?php

declare(strict_types=1);

namespace App\Form\Contact;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Validator\PhonePattern;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The public /contact form — usiscs.com's "Get In Touch" page. Field list
 * and "How Can We Help?" dropdown options are copied verbatim from the
 * live site. Not backed by an entity (unlike the RFQ flow, Contact has no
 * admin-queue requirement) — submissions are only emailed, same as
 * Sylius's own built-in contact feature, which this replaces with a
 * matching field set.
 */
final class ContactRequestType extends AbstractType
{
    public const TOPIC_JUST_IN_TIME = 'just_in_time';
    public const TOPIC_SUPPLY_PROBLEM = 'supply_problem';
    public const TOPIC_ACCOUNT_SUPPORT = 'account_support';
    public const TOPIC_REQUEST_A_QUOTE = 'request_a_quote';
    public const TOPIC_VENDOR_PARTNERSHIP = 'vendor_partnership';
    public const TOPIC_OTHER = 'other';

    public const TOPIC_LABELS = [
        self::TOPIC_JUST_IN_TIME => 'Just-in-Time Industrial Supply',
        self::TOPIC_SUPPLY_PROBLEM => 'Supply Problem / Emergency Sourcing',
        self::TOPIC_ACCOUNT_SUPPORT => 'Account Support',
        self::TOPIC_REQUEST_A_QUOTE => 'Request a Quote',
        self::TOPIC_VENDOR_PARTNERSHIP => 'Vendor Partnership',
        self::TOPIC_OTHER => 'Other',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full Name',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('company', TextType::class, [
                'label' => 'Company',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone Number',
                'required' => false,
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => PhonePattern::REGEX,
                        'message' => 'Please enter a valid phone number.',
                    ]),
                ],
            ])
            ->add('howCanWeHelp', ChoiceType::class, [
                'label' => 'How Can We Help?',
                'placeholder' => 'Select a topic...',
                'choices' => array_flip(self::TOPIC_LABELS),
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'constraints' => [new Assert\NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'contact_request';
    }
}
