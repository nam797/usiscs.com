<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Contact\ContactMessage;
use App\Form\Contact\ContactRequestType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Messages from the /contact form. Staff read them and mark them handled;
 * what the visitor wrote can't be edited, and messages only come in
 * through the form, so there's no "new" action.
 *
 * @extends AbstractCrudController<ContactMessage>
 */
class ContactMessageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ContactMessage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Contact message')
            ->setEntityLabelInPlural('Contact Messages')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['fullName', 'company', 'email', 'phone', 'message'])
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('topic')
            ->add('createdAt')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('status')->setChoices([
            'New' => ContactMessage::STATUS_NEW,
            'Handled' => ContactMessage::STATUS_HANDLED,
        ]);
        yield DateTimeField::new('createdAt')->setLabel('Received')->setDisabled();
        yield ChoiceField::new('topic')->setChoices(array_flip(ContactRequestType::TOPIC_LABELS))->setDisabled();
        yield TextField::new('fullName')->setLabel('Full name')->setDisabled();
        yield TextField::new('company')->setDisabled();
        yield EmailField::new('email')->setDisabled();
        yield TelephoneField::new('phone')->setDisabled();
        yield TextareaField::new('message')->setDisabled()->hideOnIndex();
    }
}
