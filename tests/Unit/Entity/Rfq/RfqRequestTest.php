<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Rfq;

use App\Entity\Customer\Customer;
use App\Entity\Rfq\RfqLineItem;
use App\Entity\Rfq\RfqRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RfqRequestTest extends TestCase
{
    public function testItStartsWithNoLineItemsAndTheNewStatus(): void
    {
        $request = new RfqRequest();

        self::assertCount(0, $request->getLineItems());
        self::assertSame(RfqRequest::STATUS_NEW, $request->getStatus());
    }

    public function testItHasSensibleDefaults(): void
    {
        $before = new \DateTimeImmutable();
        $request = new RfqRequest();
        $after = new \DateTimeImmutable();

        self::assertNull($request->getId());
        self::assertSame(RfqRequest::CUSTOMER_TYPE_NEW, $request->getCustomerType());
        self::assertSame(RfqRequest::URGENCY_STANDARD, $request->getUrgencyLevel());
        self::assertNull($request->getCustomer());
        self::assertSame('', $request->getFullName());
        self::assertSame('', $request->getCompany());
        self::assertSame('', $request->getEmail());
        self::assertSame('', $request->getPhone());
        self::assertNull($request->getJobSite());
        self::assertNull($request->getRequiredByDate());
        self::assertNull($request->getDeliveryAddress());
        self::assertNull($request->getAdditionalNotes());
        self::assertGreaterThanOrEqual($before, $request->getCreatedAt());
        self::assertLessThanOrEqual($after, $request->getCreatedAt());
    }

    public function testAccessorsRoundTrip(): void
    {
        $customer = new Customer();
        $date = new \DateTimeImmutable('2026-11-15');
        $request = new RfqRequest();

        $request->setCustomerType(RfqRequest::CUSTOMER_TYPE_EXISTING);
        $request->setCustomer($customer);
        $request->setFullName('Jane Doe');
        $request->setCompany('Acme Construction');
        $request->setEmail('jane@acme.example');
        $request->setPhone('912-665-7257');
        $request->setJobSite('Plant 4');
        $request->setRequiredByDate($date);
        $request->setUrgencyLevel(RfqRequest::URGENCY_EMERGENCY);
        $request->setDeliveryAddress('1 Industrial Way');
        $request->setAdditionalNotes('Forklift access only.');
        $request->setStatus(RfqRequest::STATUS_QUOTED);

        self::assertSame(RfqRequest::CUSTOMER_TYPE_EXISTING, $request->getCustomerType());
        self::assertSame($customer, $request->getCustomer());
        self::assertSame('Jane Doe', $request->getFullName());
        self::assertSame('Acme Construction', $request->getCompany());
        self::assertSame('jane@acme.example', $request->getEmail());
        self::assertSame('912-665-7257', $request->getPhone());
        self::assertSame('Plant 4', $request->getJobSite());
        self::assertSame($date, $request->getRequiredByDate());
        self::assertSame(RfqRequest::URGENCY_EMERGENCY, $request->getUrgencyLevel());
        self::assertSame('1 Industrial Way', $request->getDeliveryAddress());
        self::assertSame('Forklift access only.', $request->getAdditionalNotes());
        self::assertSame(RfqRequest::STATUS_QUOTED, $request->getStatus());
    }

    public function testAddingALineItemSetsTheBackReference(): void
    {
        $request = new RfqRequest();
        $lineItem = new RfqLineItem();

        $request->addLineItem($lineItem);

        self::assertCount(1, $request->getLineItems());
        self::assertSame($request, $lineItem->getRfqRequest());
    }

    public function testAddingTheSameLineItemTwiceDoesNotDuplicateIt(): void
    {
        $request = new RfqRequest();
        $lineItem = new RfqLineItem();

        $request->addLineItem($lineItem);
        $request->addLineItem($lineItem);

        self::assertCount(1, $request->getLineItems());
    }

    public function testRemovingALineItemDetachesItFromTheCollection(): void
    {
        $request = new RfqRequest();
        $lineItem = new RfqLineItem();

        $request->addLineItem($lineItem);
        $request->removeLineItem($lineItem);

        self::assertCount(0, $request->getLineItems());
    }

    public function testItsStringFormIsUsableBeforeItHasAnId(): void
    {
        $request = new RfqRequest();
        $request->setCompany('Acme Construction');
        $request->setFullName('Jane Doe');

        self::assertSame('RFQ #0 - Acme Construction (Jane Doe)', (string) $request);
    }

    public function testACompleteRequestIsValid(): void
    {
        self::assertCount(0, self::validator()->validate(self::validRequest()));
    }

    /** @return iterable<string, array{callable(RfqRequest): void, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'blank name' => [static fn (RfqRequest $r) => $r->setFullName(''), 'fullName'];
        yield 'blank company' => [static fn (RfqRequest $r) => $r->setCompany(''), 'company'];
        yield 'blank email' => [static fn (RfqRequest $r) => $r->setEmail(''), 'email'];
        yield 'malformed email' => [static fn (RfqRequest $r) => $r->setEmail('not-an-email'), 'email'];
        yield 'blank phone' => [static fn (RfqRequest $r) => $r->setPhone(''), 'phone'];
        yield 'malformed phone' => [static fn (RfqRequest $r) => $r->setPhone('555-1234'), 'phone'];
        yield 'name too long' => [static fn (RfqRequest $r) => $r->setFullName(str_repeat('a', 256)), 'fullName'];
        yield 'job site too long' => [static fn (RfqRequest $r) => $r->setJobSite(str_repeat('a', 256)), 'jobSite'];
        yield 'address too long' => [static fn (RfqRequest $r) => $r->setDeliveryAddress(str_repeat('a', 256)), 'deliveryAddress'];
        yield 'notes too long' => [static fn (RfqRequest $r) => $r->setAdditionalNotes(str_repeat('a', 5001)), 'additionalNotes'];
        yield 'unknown customer type' => [static fn (RfqRequest $r) => $r->setCustomerType('vip'), 'customerType'];
        yield 'unknown urgency' => [static fn (RfqRequest $r) => $r->setUrgencyLevel('yesterday'), 'urgencyLevel'];
        yield 'no line items' => [
            static function (RfqRequest $r): void {
                foreach ($r->getLineItems()->toArray() as $item) {
                    $r->removeLineItem($item);
                }
            },
            'lineItems',
        ];
        yield 'invalid line item is validated too' => [
            static function (RfqRequest $r): void {
                $item = $r->getLineItems()->first();
                self::assertInstanceOf(RfqLineItem::class, $item);
                $item->setDescription('');
            },
            'lineItems[0].description',
        ];
    }

    /** @param callable(RfqRequest): void $breakIt */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsAreRejected(callable $breakIt, string $expectedPath): void
    {
        $request = self::validRequest();
        $breakIt($request);

        $violations = self::validator()->validate($request);

        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains($expectedPath, $paths);
    }

    private static function validRequest(): RfqRequest
    {
        $request = new RfqRequest();
        $request->setFullName('Jane Doe');
        $request->setCompany('Acme Construction');
        $request->setEmail('jane@acme.example');
        $request->setPhone('(912) 665-7257');

        $item = new RfqLineItem();
        $item->setDescription('1/2" hex bolts, grade 8');
        $request->addLineItem($item);

        return $request;
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
