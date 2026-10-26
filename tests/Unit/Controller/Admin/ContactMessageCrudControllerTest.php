<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Admin;

use App\Controller\Admin\ContactMessageCrudController;
use App\Entity\Contact\ContactMessage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use PHPUnit\Framework\TestCase;

final class ContactMessageCrudControllerTest extends TestCase
{
    public function testItManagesContactMessages(): void
    {
        self::assertSame(ContactMessage::class, ContactMessageCrudController::getEntityFqcn());
    }

    public function testNewestMessagesAreListedFirstAndSearchable(): void
    {
        $crud = (new ContactMessageCrudController())->configureCrud(Crud::new())->getAsDto();

        self::assertSame(['createdAt' => 'DESC'], $crud->getDefaultSort());
        self::assertContains('message', $crud->getSearchFields() ?? []);
    }

    public function testMessagesOnlyComeInThroughTheContactForm(): void
    {
        $actions = (new ContactMessageCrudController())->configureActions(Actions::new())->getAsDto(Crud::PAGE_INDEX);

        self::assertContains(Action::NEW, $actions->getDisabledActions());
    }

    public function testStaffCanOnlyChangeTheStatus(): void
    {
        $editable = [];
        foreach ((new ContactMessageCrudController())->configureFields('edit') as $field) {
            self::assertInstanceOf(FieldInterface::class, $field);
            $dto = $field->getAsDto();
            if ('id' !== $dto->getProperty() && true !== $dto->getFormTypeOption('disabled')) {
                $editable[] = $dto->getProperty();
            }
        }

        self::assertSame(['status'], $editable);
    }
}
