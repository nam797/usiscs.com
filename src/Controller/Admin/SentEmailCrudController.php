<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Mail\SentEmail;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Every email the site sent and what became of it: delivered to the
 * recipient's mail server, bounced (with the reason), marked as spam,
 * opened. Read-only; rows come from SentEmailRecorder and Postmark's
 * webhooks.
 *
 * @extends AbstractCrudController<SentEmail>
 */
class SentEmailCrudController extends AbstractCrudController
{
    private const STATUS_LABELS = [
        'Sent, waiting to hear back' => SentEmail::STATUS_SENT,
        'Delivered' => SentEmail::STATUS_DELIVERED,
        'Bounced' => SentEmail::STATUS_BOUNCED,
        'Marked as spam' => SentEmail::STATUS_SPAM,
        'Failed to send' => SentEmail::STATUS_FAILED,
    ];

    public static function getEntityFqcn(): string
    {
        return SentEmail::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Email')
            ->setEntityLabelInPlural('Emails')
            ->setDefaultSort(['sentAt' => 'DESC'])
            ->setSearchFields(['recipients', 'originalRecipients', 'subject', 'messageId'])
            ->setHelp(Crud::PAGE_INDEX, 'Delivered = accepted by the recipient\'s mail server. Opens are only counted when the recipient\'s mail app loads images, so "not opened" doesn\'t mean unread.')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('type')
            ->add('sentAt')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield DateTimeField::new('sentAt')->setLabel('Sent');
        yield TextField::new('recipients')->setLabel('To');
        yield TextField::new('originalRecipients')->setLabel('Originally to')->setHelp('Outside production every email goes to IT support instead.')->hideOnIndex();
        yield TextField::new('subject');
        yield TextField::new('type');
        yield ChoiceField::new('status')->setChoices(self::STATUS_LABELS)->renderAsBadges([
            SentEmail::STATUS_SENT => 'secondary',
            SentEmail::STATUS_DELIVERED => 'success',
            SentEmail::STATUS_BOUNCED => 'danger',
            SentEmail::STATUS_SPAM => 'warning',
            SentEmail::STATUS_FAILED => 'danger',
        ]);
        yield TextareaField::new('statusDetail')->setLabel('Reason')->hideOnIndex();
        yield DateTimeField::new('deliveredAt')->setLabel('Delivered')->hideOnIndex();
        yield DateTimeField::new('openedAt')->setLabel('First opened');
        yield TextField::new('messageId')->setLabel('Postmark message ID')->hideOnIndex();
    }
}
