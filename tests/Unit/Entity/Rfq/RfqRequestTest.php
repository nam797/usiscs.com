<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Rfq;

use App\Entity\Rfq\RfqLineItem;
use App\Entity\Rfq\RfqRequest;
use PHPUnit\Framework\TestCase;

final class RfqRequestTest extends TestCase
{
    public function testItStartsWithNoLineItemsAndTheNewStatus(): void
    {
        $request = new RfqRequest();

        self::assertCount(0, $request->getLineItems());
        self::assertSame(RfqRequest::STATUS_NEW, $request->getStatus());
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
}
