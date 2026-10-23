<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Admin;

use App\Controller\Admin\RfqRequestCrudController;
use App\Entity\Rfq\RfqRequest;
use App\Form\Rfq\RfqLineItemType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use PHPUnit\Framework\TestCase;

final class RfqRequestCrudControllerTest extends TestCase
{
    public function testItManagesRfqRequests(): void
    {
        self::assertSame(RfqRequest::class, RfqRequestCrudController::getEntityFqcn());
    }

    public function testNewestRequestsAreListedFirstAndSearchable(): void
    {
        $crud = (new RfqRequestCrudController())->configureCrud(Crud::new())->getAsDto();

        self::assertSame(['createdAt' => 'DESC'], $crud->getDefaultSort());
        self::assertSame(['fullName', 'company', 'email', 'phone'], $crud->getSearchFields());
    }

    public function testTheQueueCanBeFilteredByStatusUrgencyTypeAndDate(): void
    {
        $filters = (new RfqRequestCrudController())->configureFilters(Filters::new())->getAsDto();

        self::assertSame(['status', 'urgencyLevel', 'customerType', 'createdAt'], array_keys($filters->all()));
    }

    public function testEveryEditableEntityPropertyHasAField(): void
    {
        $properties = array_map(static fn (FieldInterface $f): string => $f->getAsDto()->getProperty(), $this->fields('edit'));

        foreach (['status', 'customerType', 'customer', 'fullName', 'company', 'email', 'phone', 'jobSite', 'requiredByDate', 'urgencyLevel', 'deliveryAddress', 'lineItems', 'additionalNotes'] as $property) {
            self::assertContains($property, $properties);
        }
    }

    public function testChoiceFieldsOfferExactlyTheEntityConstants(): void
    {
        $choices = [];
        foreach ($this->fields('edit') as $field) {
            if ($field instanceof ChoiceField) {
                $dto = $field->getAsDto();
                $options = $dto->getCustomOption(ChoiceField::OPTION_CHOICES);
                self::assertIsArray($options);
                $choices[$dto->getProperty()] = array_values($options);
            }
        }

        self::assertSame([
            'status' => [RfqRequest::STATUS_NEW, RfqRequest::STATUS_IN_PROGRESS, RfqRequest::STATUS_QUOTED, RfqRequest::STATUS_CLOSED],
            'customerType' => [RfqRequest::CUSTOMER_TYPE_NEW, RfqRequest::CUSTOMER_TYPE_EXISTING],
            'urgencyLevel' => [RfqRequest::URGENCY_STANDARD, RfqRequest::URGENCY_RUSH, RfqRequest::URGENCY_EMERGENCY],
        ], $choices);
    }

    public function testLineItemsAreEditedWithTheSameFormAsThePublicQuotePage(): void
    {
        $collections = array_values(array_filter(
            $this->fields('edit'),
            static fn (FieldInterface $f): bool => $f instanceof CollectionField,
        ));

        self::assertCount(1, $collections);
        $lineItems = $collections[0]->getAsDto();
        self::assertSame('lineItems', $lineItems->getProperty());
        self::assertSame(RfqLineItemType::class, $lineItems->getCustomOption(CollectionField::OPTION_ENTRY_TYPE));
        self::assertTrue($lineItems->getCustomOption(CollectionField::OPTION_ALLOW_ADD));
        self::assertTrue($lineItems->getCustomOption(CollectionField::OPTION_ALLOW_DELETE));
    }

    /** @return list<FieldInterface> */
    private function fields(string $pageName): array
    {
        $fields = [];
        foreach ((new RfqRequestCrudController())->configureFields($pageName) as $field) {
            self::assertInstanceOf(FieldInterface::class, $field);
            $fields[] = $field;
        }

        return $fields;
    }
}
