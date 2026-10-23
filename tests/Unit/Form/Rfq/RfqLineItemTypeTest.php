<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form\Rfq;

use App\Entity\Rfq\RfqLineItem;
use App\Form\Rfq\RfqLineItemType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class RfqLineItemTypeTest extends TypeTestCase
{
    public function testItMapsAllColumnsOntoTheLineItem(): void
    {
        $item = new RfqLineItem();
        $form = $this->factory->create(RfqLineItemType::class, $item);

        $form->submit(['quantity' => '4', 'partNumber' => 'GL-9', 'description' => 'Gloves', 'unitOfMeasure' => 'pair']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(4, $item->getQuantity());
        self::assertSame('GL-9', $item->getPartNumber());
        self::assertSame('Gloves', $item->getDescription());
        self::assertSame('pair', $item->getUnitOfMeasure());
    }

    public function testABlankQuantityFallsBackToOne(): void
    {
        // Regression: quantity is a non-nullable int, so a blank value used
        // to throw a TypeError (500) instead of using the default.
        $item = new RfqLineItem();
        $form = $this->factory->create(RfqLineItemType::class, $item);

        $form->submit(['quantity' => '', 'description' => 'Gloves']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(1, $item->getQuantity());
    }

    public function testABlankDescriptionFailsValidationInsteadOfCrashing(): void
    {
        $item = new RfqLineItem();
        $form = $this->factory->create(RfqLineItemType::class, $item);

        $form->submit(['quantity' => '1', 'description' => '']);

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('description')->isValid());
        self::assertSame('', $item->getDescription());
    }

    public function testAZeroQuantityIsRejected(): void
    {
        $form = $this->factory->create(RfqLineItemType::class, new RfqLineItem());

        $form->submit(['quantity' => '0', 'description' => 'Gloves']);

        self::assertFalse($form->get('quantity')->isValid());
    }

    public function testANonNumericQuantityIsRejected(): void
    {
        $form = $this->factory->create(RfqLineItemType::class, new RfqLineItem());

        $form->submit(['quantity' => 'lots', 'description' => 'Gloves']);

        self::assertFalse($form->get('quantity')->isSynchronized());
        self::assertFalse($form->isValid());
    }

    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()),
        ];
    }
}
