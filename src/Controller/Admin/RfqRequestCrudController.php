<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Rfq\RfqRequest;
use App\Form\Rfq\RfqLineItemType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Admin queue for the "can't find what you need" RFQ flow. Field groupings
 * mirror the sections of the RFQ Flow Spec doc (Customer Info, Project
 * Details, Items Needed, Additional Info) rather than the underlying
 * entity's flat property list.
 *
 * @extends AbstractCrudController<RfqRequest>
 */
class RfqRequestCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RfqRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('RFQ')
            ->setEntityLabelInPlural('RFQ Requests')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['fullName', 'company', 'email', 'phone'])
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('urgencyLevel')
            ->add('customerType')
            ->add('createdAt')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield FormField::addFieldset('Status');
        yield ChoiceField::new('status')->setChoices([
            'New' => RfqRequest::STATUS_NEW,
            'In progress' => RfqRequest::STATUS_IN_PROGRESS,
            'Quoted' => RfqRequest::STATUS_QUOTED,
            'Closed' => RfqRequest::STATUS_CLOSED,
        ]);
        yield DateTimeField::new('createdAt')->hideOnForm();

        yield FormField::addFieldset('Customer info');
        yield ChoiceField::new('customerType')->setChoices([
            'New customer' => RfqRequest::CUSTOMER_TYPE_NEW,
            'Existing customer' => RfqRequest::CUSTOMER_TYPE_EXISTING,
        ]);
        yield AssociationField::new('customer')
            ->setHelp('Only relevant when Customer Type is "Existing customer".')
            ->hideOnIndex();
        yield TextField::new('fullName')->setLabel('Full name');
        yield TextField::new('company');
        yield EmailField::new('email');
        yield TelephoneField::new('phone');

        yield FormField::addFieldset('Project details');
        yield TextField::new('jobSite')->setLabel('Job site / facility name')->hideOnIndex();
        yield DateField::new('requiredByDate')->setLabel('Required by')->hideOnIndex();
        yield ChoiceField::new('urgencyLevel')->setChoices([
            'Standard' => RfqRequest::URGENCY_STANDARD,
            'Rush' => RfqRequest::URGENCY_RUSH,
            'Emergency' => RfqRequest::URGENCY_EMERGENCY,
        ]);
        yield TextField::new('deliveryAddress')->hideOnIndex();

        yield FormField::addFieldset('Items needed');
        yield CollectionField::new('lineItems')
            ->setEntryType(RfqLineItemType::class)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->hideOnIndex();

        yield FormField::addFieldset('Additional info');
        yield TextareaField::new('additionalNotes')
            ->setLabel('Additional notes / special requirements')
            ->hideOnIndex();
    }
}
