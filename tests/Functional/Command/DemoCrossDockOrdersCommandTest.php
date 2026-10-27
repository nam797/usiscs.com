<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Distributor\FulfilmentRoute;
use App\Distributor\Warehouse\WarehouseStatus;
use App\Entity\Addressing\Zone;
use App\Entity\Channel\Channel;
use App\Entity\Order\Order;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Shipping\ShippingMethod;
use App\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** app:dev:demo-cross-dock: sample orders in every warehouse state, replaced on each run, nothing sent. */
final class DemoCrossDockOrdersCommandTest extends FunctionalTestCase
{
    public function testItCreatesOrdersInEveryWarehouseStateAndReplacesThemOnTheNextRun(): void
    {
        $this->ensureProduct('E2E_DEMO_GLOVES', 'Demo Test Gloves', 'demo-test-gloves', 'safety_ppe');
        $this->ensureShippingMethod();
        $transport = static::getContainer()->get('messenger.transport.distributor');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->runCommand([]);
        $this->runCommand([]);

        $this->entityManager()->clear();
        /** @var list<Order> $orders */
        $orders = $this->entityManager()->getRepository(Order::class)->createQueryBuilder('o')
            ->where('o.number LIKE :demo')->setParameter('demo', 'DEMO-%')->orderBy('o.number')->getQuery()->getResult();
        self::assertCount(6, $orders, 'the second run replaced the first');
        self::assertInstanceOf(Order::class, $orders[0]);
        self::assertSame(FulfilmentRoute::Mixed, $orders[0]->getFulfilmentRoute());

        $statuses = [];
        foreach ($this->entityManager()->getRepository(PurchaseOrder::class)->findBy(['order' => $orders]) as $purchaseOrder) {
            $status = $purchaseOrder->getWarehouseStatus();
            $statuses[] = null === $status ? 'direct '.$purchaseOrder->getState() : $status->value;
        }
        sort($statuses);
        $expected = [
            WarehouseStatus::AwaitingDistributor->value,
            WarehouseStatus::InTransit->value,
            WarehouseStatus::InTransit->value,
            WarehouseStatus::Overdue->value,
            WarehouseStatus::Received->value,
            WarehouseStatus::ShippedToCustomer->value,
            'direct awaiting_manual_entry',
            'direct shipped',
        ];
        sort($expected);
        self::assertSame($expected, $statuses);
        self::assertCount(0, $transport->getSent(), 'nothing is queued to a distributor');
        self::assertEmailCount(0);

        $this->runCommand(['--delete' => true]);
        $this->entityManager()->clear();
        self::assertSame(0, $this->entityManager()->getRepository(Order::class)->count(['number' => 'DEMO-001']));
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:dev:demo-cross-dock'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }

    private function ensureShippingMethod(): void
    {
        $entityManager = $this->entityManager();
        if (null !== $entityManager->getRepository(ShippingMethod::class)->findOneBy([])) {
            return;
        }

        $zone = $entityManager->getRepository(Zone::class)->findOneBy(['code' => 'US']);
        self::assertInstanceOf(Zone::class, $zone, 'Load the "e2e" fixture suite first.');
        $channel = $entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(Channel::class, $channel);
        $factory = static::getContainer()->get('sylius.factory.shipping_method');
        self::assertInstanceOf(FactoryInterface::class, $factory);
        $method = $factory->createNew();
        self::assertInstanceOf(ShippingMethodInterface::class, $method);
        $method->setCode('e2e_standard');
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Standard');
        $method->setZone($zone);
        $method->setCalculator('flat_rate');
        $method->setConfiguration(['default' => ['amount' => 1500]]);
        $method->addChannel($channel);
        $entityManager->persist($method);
        $entityManager->flush();
    }
}
