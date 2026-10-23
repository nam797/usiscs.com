<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form\Rfq;

use App\Entity\Rfq\RfqLineItem;
use App\Entity\Rfq\RfqRequest;
use App\Form\Rfq\RfqRequestType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class RfqRequestTypeTest extends TypeTestCase
{
    /** @return array<string, mixed> */
    private static function validSubmission(): array
    {
        return [
            'customerType' => RfqRequest::CUSTOMER_TYPE_EXISTING,
            'fullName' => 'Jane Doe',
            'company' => 'Acme Construction',
            'email' => 'jane@acme.example',
            'phone' => '(912) 665-7257',
            'jobSite' => 'Plant 4',
            'requiredByDate' => '2026-11-15',
            'urgencyLevel' => RfqRequest::URGENCY_RUSH,
            'deliveryAddress' => '1 Industrial Way',
            'lineItems' => [
                ['quantity' => '10', 'partNumber' => 'HB-500', 'description' => 'Hex bolt', 'unitOfMeasure' => 'box'],
                ['quantity' => '2', 'partNumber' => '', 'description' => 'Pallet jack', 'unitOfMeasure' => ''],
            ],
            'additionalNotes' => 'Forklift access only.',
        ];
    }

    public function testAValidSubmissionPopulatesTheRequest(): void
    {
        [$form, $request] = $this->submit(self::validSubmission());

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(RfqRequest::CUSTOMER_TYPE_EXISTING, $request->getCustomerType());
        self::assertSame('Jane Doe', $request->getFullName());
        self::assertSame('Acme Construction', $request->getCompany());
        self::assertSame('jane@acme.example', $request->getEmail());
        self::assertSame('(912) 665-7257', $request->getPhone());
        self::assertSame('Plant 4', $request->getJobSite());
        self::assertSame('2026-11-15', $request->getRequiredByDate()?->format('Y-m-d'));
        self::assertSame(RfqRequest::URGENCY_RUSH, $request->getUrgencyLevel());
        self::assertSame('1 Industrial Way', $request->getDeliveryAddress());
        self::assertSame('Forklift access only.', $request->getAdditionalNotes());
    }

    public function testLineItemsAreAddedThroughTheEntitySoTheyAreLinkedBack(): void
    {
        [, $request] = $this->submit(self::validSubmission());

        $items = $request->getLineItems()->toArray();
        self::assertCount(2, $items);
        self::assertContainsOnlyInstancesOf(RfqLineItem::class, $items);

        $first = array_values($items)[0];
        self::assertSame(10, $first->getQuantity());
        self::assertSame('HB-500', $first->getPartNumber());
        self::assertSame('Hex bolt', $first->getDescription());
        self::assertSame('box', $first->getUnitOfMeasure());
        self::assertSame($request, $first->getRfqRequest());

        $second = array_values($items)[1];
        self::assertNull($second->getPartNumber());
        self::assertNull($second->getUnitOfMeasure());
    }

    public function testOptionalProjectFieldsCanBeLeftBlank(): void
    {
        $data = self::validSubmission();
        $data['jobSite'] = '';
        $data['requiredByDate'] = '';
        $data['deliveryAddress'] = '';
        $data['additionalNotes'] = '';

        [$form, $request] = $this->submit($data);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertNull($request->getJobSite());
        self::assertNull($request->getRequiredByDate());
        self::assertNull($request->getDeliveryAddress());
        self::assertNull($request->getAdditionalNotes());
    }

    public function testBlankRequiredFieldsFailValidationInsteadOfCrashing(): void
    {
        // Regression: these map to non-nullable string properties, and a
        // blank submission used to throw a TypeError (500) instead of
        // producing validation errors.
        $data = self::validSubmission();
        foreach (['fullName', 'company', 'email', 'phone'] as $field) {
            $data[$field] = '';
        }

        [$form, $request] = $this->submit($data);

        self::assertFalse($form->isValid());
        self::assertSame('', $request->getFullName());
        foreach (['fullName', 'company', 'email', 'phone'] as $field) {
            self::assertFalse($form->get($field)->isValid(), sprintf('Expected "%s" to carry the error.', $field));
        }
    }

    public function testASubmissionWithoutLineItemsIsRejected(): void
    {
        $data = self::validSubmission();
        $data['lineItems'] = [];

        [$form] = $this->submit($data);

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('lineItems')->isValid());
    }

    public function testUnknownChoicesAreRejected(): void
    {
        $data = self::validSubmission();
        $data['customerType'] = 'vip';
        $data['urgencyLevel'] = 'yesterday';

        [$form] = $this->submit($data);

        self::assertFalse($form->get('customerType')->isValid());
        self::assertFalse($form->get('urgencyLevel')->isValid());
    }

    public function testTheChoiceListsMatchTheEntityConstants(): void
    {
        $form = $this->factory->create(RfqRequestType::class, new RfqRequest());

        self::assertSame(
            [RfqRequest::CUSTOMER_TYPE_NEW, RfqRequest::CUSTOMER_TYPE_EXISTING],
            array_values((array) $form->get('customerType')->getConfig()->getOption('choices')),
        );
        self::assertSame(
            [RfqRequest::URGENCY_STANDARD, RfqRequest::URGENCY_RUSH, RfqRequest::URGENCY_EMERGENCY],
            array_values((array) $form->get('urgencyLevel')->getConfig()->getOption('choices')),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{FormInterface, RfqRequest}
     */
    private function submit(array $data): array
    {
        $request = new RfqRequest();
        $form = $this->factory->create(RfqRequestType::class, $request);
        $form->submit($data);

        return [$form, $request];
    }

    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()),
        ];
    }
}
