<?php

declare(strict_types=1);

namespace App\Tests\Functional\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorOrderStatus;
use App\Distributor\DistributorRegistry;
use App\Distributor\FulfilmentRoute;
use App\Distributor\Warehouse\WarehouseStatus;
use App\Entity\Order\Order;
use App\Distributor\Fake\FakeDistributor;
use App\Distributor\Message\SubmitPurchaseOrder;
use App\Distributor\Message\SubmitPurchaseOrderHandler;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Entity\Addressing\Zone;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Channel\Channel;
use App\Entity\Shipping\ShippingMethod;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderEvent;
use App\Entity\Product\ProductVariant;
use App\Tests\Functional\FunctionalTestCase;
use App\Entity\Payment\PaymentMethod;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A paid order through to its distributor: Sylius's payment transition
 * creates the purchase order, the outbox queues it, the worker's handler
 * sends it to the test distributor, and the backoffice shows the timeline.
 */
final class PurchaseOrderFlowTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->transport()->reset();
        // Each test starts with no purchase orders (lines, events and tracking cascade in the database).
        $this->entityManager()->createQuery('DELETE FROM '.PurchaseOrder::class)->execute();
    }

    public function testAPaidOrderIsQueuedSentAndLogged(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect);

        $purchaseOrders = $this->entityManager()->getRepository(PurchaseOrder::class)->findBy(['order' => $order]);
        self::assertCount(1, $purchaseOrders);
        $purchaseOrder = $purchaseOrders[0];
        self::assertSame($order->getNumber().'-1', $purchaseOrder->getNumber());
        self::assertSame(FakeDistributor::CODE, $purchaseOrder->getDistributor());
        self::assertNotNull($purchaseOrder->getDispatchedAt(), 'the outbox records that it was queued');

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
        self::assertSame($purchaseOrder->getId(), $message->purchaseOrderId);

        $this->handler()($message);
        // A second delivery of the same message does nothing.
        $this->handler()($message);

        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId());
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        self::assertSame(PurchaseOrderState::SUBMITTED, $purchaseOrder->getState());
        self::assertSame('FAKE-'.$purchaseOrder->getNumber(), $purchaseOrder->getReference());
        self::assertSame(1, $purchaseOrder->getAttempts());
        self::assertSame(
            [PurchaseOrderEventType::Created, PurchaseOrderEventType::Start, PurchaseOrderEventType::Submit],
            array_values($purchaseOrder->getEvents()->map(static fn (PurchaseOrderEvent $e): PurchaseOrderEventType => $e->getType())->toArray()),
        );
    }

    public function testWhenTheDistributorShipsTheCustomerGetsTrackingAndTheShippedEmail(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect);
        $message = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
        $this->handler()($message);
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);

        $fake = $this->fakeDistributor();
        $fake->reportStatus($purchaseOrder->getNumber(), new DistributorOrderStatus(
            'FAKE-1',
            PurchaseOrderState::SHIPPED,
            'Test distributor: shipped',
            ['E2E_PO_GLOVES' => ['shipped' => 2, 'backordered' => 0]],
            [['trackingNumber' => '1ZTEST0001', 'carrier' => 'UPS GRD COML']],
        ));

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:distributor:sync-orders'));
        $tester->execute(['--no-alerts' => true]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId());
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        self::assertSame(PurchaseOrderState::SHIPPED, $purchaseOrder->getState());
        self::assertSame(2, $purchaseOrder->getLines()->first() ? $purchaseOrder->getLines()->first()->getQuantityShipped() : null);
        $customerOrder = $purchaseOrder->getOrder();
        $shipment = $customerOrder->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        self::assertSame('1ZTEST0001', $shipment->getTracking());
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', (string) $customerOrder->getCustomer()?->getEmail());

        // Running again changes nothing and sends nothing more.
        $tester->execute(['--no-alerts' => true]);
        self::assertEmailCount(1);
    }

    public function testWithoutAnOrderApiStaffAreEmailedToPlaceItByHand(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect, SupplierItem::ORS_NASCO);
        $message = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);

        $this->handler()($message);

        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $this->entityManager()->refresh($purchaseOrder);
        self::assertSame(PurchaseOrderState::AWAITING_MANUAL_ENTRY, $purchaseOrder->getState());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', self::CHANNEL_CONTACT_EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', sprintf('Place purchase order %s with ORS Nasco', $purchaseOrder->getNumber()));
        self::assertEmailTextBodyContains($email, 'E2E_PO_GLOVES');
        self::assertEmailTextBodyContains($email, '1 Main St');
        self::assertEmailTextBodyContains($email, sprintf('/backoffice/purchase-order/%d', $purchaseOrder->getId()));
    }

    public function testStaffSeeTheTimelineAndCanRetryWhatNeedsAttention(): void
    {
        $order = $this->paidOrder(Fulfilment::Unconfirmed);
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
        $this->handler()($message);
        $id = $purchaseOrder->getId();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', sprintf('/backoffice/purchase-order/%d', $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Needs attention');
        self::assertSelectorTextContains('body', 'Fulfilment is not confirmed');
        self::assertSelectorTextContains('body', 'E2E_PO_GLOVES');

        $this->client->request('GET', sprintf('/admin/orders/%d', $order->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $purchaseOrder->getNumber());

        // Fix the fulfilment in the backoffice, then retry.
        $crawler = $this->client->request('GET', sprintf('/backoffice/purchase-order/%d/edit', $id));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="PurchaseOrder"]')->form();
        $option = $crawler->filter('select[name="PurchaseOrder[fulfilment]"] option')->reduce(static fn ($o): bool => Fulfilment::OrsDirect->label() === trim($o->text()));
        self::assertCount(1, $option);
        $form['PurchaseOrder[fulfilment]'] = (string) $option->attr('value');
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $id);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        self::assertSame(Fulfilment::OrsDirect, $purchaseOrder->getFulfilment());
        self::assertSame(PurchaseOrderEventType::Edited, $purchaseOrder->getLastEvent()?->getType());
        $this->transport()->reset();

        $crawler = $this->client->request('GET', sprintf('/backoffice/purchase-order/%d/retry', $id));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Retry')->form(['note' => 'Fulfilment confirmed with ORS']));
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $id);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        self::assertSame(PurchaseOrderState::QUEUED, $purchaseOrder->getState());
        $event = $purchaseOrder->getLastEvent();
        self::assertNotNull($event);
        self::assertSame('admin@usiscs.test', $event->getActor());
        self::assertSame('Fulfilment confirmed with ORS', $event->getMessage());
        self::assertCount(1, $this->transport()->getSent(), 'retrying queues it again');
    }

    public function testTheRetryFormRefusesAMissingCsrfToken(): void
    {
        $order = $this->paidOrder(Fulfilment::Unconfirmed);
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $message = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
        $this->handler()($message);

        $this->loginAsAdmin();
        $this->client->request('POST', sprintf('/backoffice/purchase-order/%d/retry', $purchaseOrder->getId()), ['note' => 'x']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'The form expired');
        $this->entityManager()->clear();
        self::assertSame(PurchaseOrderState::NEEDS_ATTENTION, $this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId())?->getState());
    }

    public function testTheWarehouseReceivesCrossDockItemsAndShipsThemOn(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect, secondItem: Fulfilment::CrossDock);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(FulfilmentRoute::Mixed, $order->getFulfilmentRoute());
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
            $this->handler()($message);
        }

        $repository = $this->entityManager()->getRepository(PurchaseOrder::class);
        $direct = $repository->findOneBy(['order' => $order, 'fulfilment' => Fulfilment::OrsDirect]);
        $crossDock = $repository->findOneBy(['order' => $order, 'fulfilment' => Fulfilment::CrossDock]);
        self::assertInstanceOf(PurchaseOrder::class, $direct);
        self::assertInstanceOf(PurchaseOrder::class, $crossDock);

        // The distributor ships both: the gloves to the customer, the helmet to us.
        $fake = $this->fakeDistributor();
        $fake->reportStatus($direct->getNumber(), new DistributorOrderStatus('FAKE-D', PurchaseOrderState::SHIPPED, 'shipped', [], [['trackingNumber' => '1ZDIRECT01', 'carrier' => 'UPS']]));
        $fake->reportStatus($crossDock->getNumber(), new DistributorOrderStatus('FAKE-X', PurchaseOrderState::SHIPPED, 'shipped', [], [['trackingNumber' => '1ZINBOUND01', 'carrier' => 'UPS']]));
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:distributor:sync-orders'));
        $tester->execute(['--no-alerts' => true]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager()->clear();
        $crossDock = $repository->find($crossDock->getId());
        self::assertInstanceOf(PurchaseOrder::class, $crossDock);
        self::assertNotNull($crossDock->getExpectedAt(), 'the expected date comes from the ship date');
        self::assertSame(WarehouseStatus::InTransit, $crossDock->getWarehouseStatus());
        $shipment = $crossDock->getOrder()->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        self::assertSame('1ZDIRECT01', $shipment->getTracking(), 'the parcel to our warehouse is not the customer\'s tracking');
        self::assertNotSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState(), 'the helmet hasn\'t shipped to the customer yet');
        self::assertEmailCount(0);

        // The warehouse list shows it coming in; the direct one isn't there.
        $this->loginAsAdmin();
        $this->client->request('GET', '/backoffice/warehouse?'.http_build_query(['filters' => ['warehouseStatus' => ['comparison' => '=', 'value' => 'not_received']]]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $crossDock->getNumber());
        self::assertSelectorTextContains('body', 'On its way to us');
        self::assertSelectorTextNotContains('body', $direct->getNumber());
        foreach (WarehouseStatus::cases() as $status) {
            foreach (['=', '!='] as $comparison) {
                $this->client->request('GET', '/backoffice/warehouse?'.http_build_query(['filters' => ['warehouseStatus' => ['comparison' => $comparison, 'value' => $status->value]]]));
                self::assertResponseIsSuccessful(sprintf('filter %s %s', $comparison, $status->value));
                $listed = str_contains((string) $this->client->getResponse()->getContent(), $crossDock->getNumber());
                self::assertSame((WarehouseStatus::InTransit === $status) === ('=' === $comparison), $listed, sprintf('filter %s %s', $comparison, $status->value));
            }
        }

        // The backoffice home page lists it as coming in, with links to the lists.
        $crawler = $this->client->request('GET', '/backoffice');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Warehouse: cross-dock deliveries');
        self::assertSelectorTextContains('.card', 'Overdue');
        self::assertSame(1, $crawler->filter('a[href*="/backoffice/warehouse/'.$crossDock->getId().'"]')->count(), 'in "Coming in"');
        self::assertGreaterThan(0, $crawler->filter('a[href*="filters%5BwarehouseStatus%5D%5Bvalue%5D=overdue"]')->count());

        // Purchase Orders filters on fulfilment too.
        $this->client->request('GET', '/backoffice/purchase-order?'.http_build_query(['filters' => ['fulfilment' => ['comparison' => '=', 'value' => 'cross_dock']]]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $crossDock->getNumber());
        self::assertSelectorTextNotContains('body', $direct->getNumber());

        // The Sylius order list filters on it, and the order page says where it is.
        $this->client->request('GET', '/admin/orders/?'.http_build_query(['criteria' => ['fulfilmentRoute' => 'mixed', 'number' => ['value' => (string) $order->getNumber()]]]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $order->getNumber());
        self::assertSelectorTextContains('body', 'Mixed');
        $this->client->request('GET', '/admin/orders/?'.http_build_query(['criteria' => ['fulfilmentRoute' => 'direct', 'number' => ['value' => (string) $order->getNumber()]]]));
        self::assertSelectorTextNotContains('body', '#'.$order->getNumber());
        $this->client->request('GET', sprintf('/admin/orders/%d', $order->getId()));
        self::assertSelectorTextContains('body', 'Warehouse: On its way to us');

        // Its page lists every line in full, and the timeline.
        $this->client->request('GET', sprintf('/backoffice/warehouse/%d', $crossDock->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Distributor SKU');
        self::assertSelectorTextContains('body', 'E2E_PO_HELMET');
        self::assertSelectorTextContains('body', 'Timeline');

        // Checked in, then shipped on with our tracking.
        $crawler = $this->client->request('GET', sprintf('/backoffice/warehouse/%d/mark-received', $crossDock->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Mark received')->form(['note' => 'Box dented, helmet fine']));
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', sprintf('/backoffice/warehouse/%d/ship-to-customer', $crossDock->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Ship to customer')->form(['carrier' => 'UPS Ground', 'tracking' => '1ZOURS0001']));
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $crossDock = $repository->find($crossDock->getId());
        self::assertInstanceOf(PurchaseOrder::class, $crossDock);
        self::assertSame(WarehouseStatus::ShippedToCustomer, $crossDock->getWarehouseStatus());
        self::assertSame('admin@usiscs.test', $crossDock->getReceivedBy());
        $shipment = $crossDock->getOrder()->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        self::assertEqualsCanonicalizing(['1ZDIRECT01', '1ZOURS0001'], explode(', ', (string) $shipment->getTracking()));
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
        self::assertEmailCount(1);

        $crawler = $this->client->request('GET', '/backoffice');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href*="/backoffice/warehouse/'.$crossDock->getId().'"]')->count(), 'gone once shipped on');
    }

    public function testAPaidOrderGoesToTheCheapestSourceWithStock(): void
    {
        $product = $this->ensureProduct('E2E_PO_GLOVES', 'Purchase Order Test Gloves', 'purchase-order-test-gloves', 'safety_ppe');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariant::class, $variant);
        self::onlySource($variant, SupplierItem::ORS_NASCO, Fulfilment::OrsDirect);
        // Doctrine inserts before it deletes: drop the old test-distributor source first.
        $this->entityManager()->flush();
        $ors = $variant->sourceFor(SupplierItem::ORS_NASCO);
        $ors->setCostInCents(900);
        $ors->recordStock(100);
        $fake = $variant->sourceFor(FakeDistributor::CODE);
        $fake->setFulfilment(Fulfilment::CrossDock);
        $fake->setEnabled(true);
        $fake->setCostInCents(700);
        $fake->recordStock(100);
        $this->entityManager()->flush();

        // An order for 2: both have stock, the test distributor is cheaper.
        $order = $this->paidOrder(Fulfilment::CrossDock, keepOtherSources: true);

        $purchaseOrders = $this->entityManager()->getRepository(PurchaseOrder::class)->findBy(['order' => $order]);
        self::assertCount(1, $purchaseOrders);
        self::assertSame(FakeDistributor::CODE, $purchaseOrders[0]->getDistributor());
        self::assertSame(Fulfilment::CrossDock, $purchaseOrders[0]->getFulfilment());
        self::assertSame(700, $purchaseOrders[0]->getLines()->first() ? $purchaseOrders[0]->getLines()->first()->getUnitCostInCents() : null);
        $created = $purchaseOrders[0]->getEvents()->first();
        self::assertNotFalse($created);
        self::assertStringContainsString('Test distributor $7.00 (stock 100) over ORS Nasco $9.00 (stock 100)', (string) $created->getMessage());
    }

    public function testAHeldCardPaymentSendsPurchaseOrdersAndIsChargedWhenTheDistributorShips(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect, authorizeOnly: true);
        self::assertSame('authorized', $order->getPaymentState());

        // Purchase orders go out on the authorization, not on payment.
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $message = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
        $this->handler()($message);

        $this->fakeDistributor()->reportStatus($purchaseOrder->getNumber(), new DistributorOrderStatus('FAKE-1', PurchaseOrderState::SHIPPED, 'shipped', [], [['trackingNumber' => '1ZHELD0001', 'carrier' => 'UPS']]));
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:distributor:sync-orders'));
        $tester->execute(['--no-alerts' => true]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId());
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        self::assertSame('paid', $purchaseOrder->getOrder()->getPaymentState());
        self::assertSame('completed', $purchaseOrder->getOrder()->getPayments()->first() ? $purchaseOrder->getOrder()->getPayments()->first()->getState() : null);
        // Once, with the reason: the admin-action logger stays out of automatic charges.
        $captures = array_filter($purchaseOrder->getEvents()->toArray(), static fn (PurchaseOrderEvent $e): bool => PurchaseOrderEventType::PaymentCaptured === $e->getType());
        self::assertCount(1, $captures);
        $capture = current($captures);
        self::assertNotFalse($capture);
        self::assertStringContainsString('shipped purchase order', (string) $capture->getMessage());
        self::assertCount(1, $this->entityManager()->getRepository(PurchaseOrder::class)->findBy(['order' => $purchaseOrder->getOrder()]), 'paying doesn\'t order the goods twice');
    }

    public function testAHoldIsReleasedWhenEveryPurchaseOrderIsCancelled(): void
    {
        $order = $this->paidOrder(Fulfilment::Unconfirmed, authorizeOnly: true);
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $message = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitPurchaseOrder::class, $message);
        $this->handler()($message);

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', sprintf('/backoffice/purchase-order/%d/cancel', $purchaseOrder->getId()));
        $this->client->submit($crawler->selectButton('Cancel')->form(['note' => 'ORS discontinued it']));
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId());
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        self::assertSame('cancelled', $purchaseOrder->getOrder()->getPayments()->first() ? $purchaseOrder->getOrder()->getPayments()->first()->getState() : null);
        // On the timeline right after the cancellation.
        self::assertSame(PurchaseOrderEventType::PaymentReleased, $purchaseOrder->getLastEvent()?->getType());
    }

    public function testChargingAndRefundingInTheAdminShowOnTheTimeline(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect, authorizeOnly: true);
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);

        $this->loginAsAdmin();
        foreach (['complete', 'refund'] as $action) {
            $crawler = $this->client->request('GET', sprintf('/admin/orders/%d', $order->getId()));
            self::assertResponseIsSuccessful();
            $form = $crawler->filter(sprintf('form[action$="/%s"]', $action));
            self::assertCount(1, $form, sprintf('a %s button for the payment', $action));
            $this->client->submit($form->form());
            self::assertResponseRedirects();
        }

        $this->entityManager()->clear();
        $purchaseOrder = $this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId());
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $payments = array_values(array_filter(
            $purchaseOrder->getEvents()->toArray(),
            static fn (PurchaseOrderEvent $e): bool => in_array($e->getType(), [PurchaseOrderEventType::PaymentCaptured, PurchaseOrderEventType::PaymentRefunded], true),
        ));
        self::assertSame(
            [[PurchaseOrderEventType::PaymentCaptured, 'admin@usiscs.test'], [PurchaseOrderEventType::PaymentRefunded, 'admin@usiscs.test']],
            array_map(static fn (PurchaseOrderEvent $e): array => [$e->getType(), $e->getActor()], $payments),
        );
        self::assertStringContainsString('in the Sylius admin', (string) $payments[0]->getMessage());
    }

    public function testAPaymentStillHeldAfterSixDaysIsCharged(): void
    {
        $order = $this->paidOrder(Fulfilment::OrsDirect, authorizeOnly: true);
        $order->setCheckoutCompletedAt(new \DateTime('-7 days'));
        $recent = $this->paidOrder(Fulfilment::OrsDirect, authorizeOnly: true);
        $this->entityManager()->flush();

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:payments:capture-due'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager()->clear();
        self::assertSame('paid', $this->entityManager()->find(Order::class, $order->getId())?->getPaymentState());
        self::assertSame('authorized', $this->entityManager()->find(Order::class, $recent->getId())?->getPaymentState(), 'only the old one');
    }

    public function testShipToCustomerIsRefusedBeforeItIsReceived(): void
    {
        $order = $this->paidOrder(Fulfilment::CrossDock);
        $purchaseOrder = $this->entityManager()->getRepository(PurchaseOrder::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(PurchaseOrder::class, $purchaseOrder);

        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/backoffice/warehouse/%d/ship-to-customer', $purchaseOrder->getId()));

        self::assertResponseRedirects();
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(PurchaseOrder::class, $purchaseOrder->getId())?->getForwardedAt());
    }

    /** @param ?Fulfilment $secondItem when set, adds a helmet with this fulfilment */
    /** @param bool $authorizeOnly a card payment held (authorized) instead of a paid order */
    private function paidOrder(Fulfilment $fulfilment, string $distributor = FakeDistributor::CODE, ?Fulfilment $secondItem = null, bool $keepOtherSources = false, bool $authorizeOnly = false): OrderInterface
    {
        $container = static::getContainer();
        $entityManager = $this->entityManager();

        $product = $this->ensureProduct('E2E_PO_GLOVES', 'Purchase Order Test Gloves', 'purchase-order-test-gloves', 'safety_ppe');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariant::class, $variant);
        if (!$keepOtherSources) {
            self::onlySource($variant, $distributor, $fulfilment);
        }

        $channel = $entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(Channel::class, $channel);

        $orderFactory = $container->get('sylius.factory.order');
        self::assertInstanceOf(FactoryInterface::class, $orderFactory);
        $order = $orderFactory->createNew();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setNumber('E2E'.bin2hex(random_bytes(4)));

        $customerFactory = $container->get('sylius.factory.customer');
        self::assertInstanceOf(FactoryInterface::class, $customerFactory);
        $customer = $customerFactory->createNew();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setEmail(sprintf('buyer-%s@example.test', bin2hex(random_bytes(4))));
        $order->setCustomer($customer);

        $addressFactory = $container->get('sylius.factory.address');
        self::assertInstanceOf(FactoryInterface::class, $addressFactory);
        foreach (['setShippingAddress', 'setBillingAddress'] as $setter) {
            $address = $addressFactory->createNew();
            self::assertInstanceOf(AddressInterface::class, $address);
            $address->setFirstName('Jane');
            $address->setLastName('Doe');
            $address->setStreet('1 Main St');
            $address->setCity('Springfield');
            $address->setPostcode('62701');
            $address->setCountryCode('US');
            $order->{$setter}($address);
        }

        $itemFactory = $container->get('sylius.factory.order_item');
        self::assertInstanceOf(FactoryInterface::class, $itemFactory);
        $item = $itemFactory->createNew();
        self::assertInstanceOf(OrderItemInterface::class, $item);
        $item->setVariant($variant);
        $item->setUnitPrice(1299);
        $quantityModifier = $container->get('sylius.modifier.order_item_quantity');
        self::assertInstanceOf(OrderItemQuantityModifierInterface::class, $quantityModifier);
        $quantityModifier->modify($item, 2);
        $order->addItem($item);
        $items = [$item];

        if (null !== $secondItem) {
            $helmet = $this->ensureProduct('E2E_PO_HELMET', 'Purchase Order Test Helmet', 'purchase-order-test-helmet', 'safety_ppe');
            $helmetVariant = $helmet->getVariants()->first();
            self::assertInstanceOf(ProductVariant::class, $helmetVariant);
            self::onlySource($helmetVariant, $distributor, $secondItem);
            $helmetItem = $itemFactory->createNew();
            self::assertInstanceOf(OrderItemInterface::class, $helmetItem);
            $helmetItem->setVariant($helmetVariant);
            $helmetItem->setUnitPrice(2499);
            $quantityModifier->modify($helmetItem, 1);
            $order->addItem($helmetItem);
            $items[] = $helmetItem;
        }

        // One shipment, ready to ship, holding every unit (as checkout makes it).
        $shipmentFactory = $container->get('sylius.factory.shipment');
        self::assertInstanceOf(FactoryInterface::class, $shipmentFactory);
        $shipment = $shipmentFactory->createNew();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $shipment->setMethod($this->shippingMethod($channel));
        $shipment->setState(ShipmentInterface::STATE_READY);
        foreach ($items as $orderItem) {
            foreach ($orderItem->getUnits() as $unit) {
                self::assertInstanceOf(ShipmentUnitInterface::class, $unit);
                $shipment->addUnit($unit);
            }
        }
        $order->addShipment($shipment);

        // A placed order (Sylius's admin hides carts), not paid yet.
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime());
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $order->setShippingState(OrderShippingStates::STATE_READY);

        $entityManager->persist($order);
        $entityManager->flush();

        $stateMachine = $container->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);
        if ($authorizeOnly) {
            // As the Stripe plugin does at checkout: the payment is authorized, and Sylius moves the order to "authorized".
            $payment = $this->payment($order, $channel);
            $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_AUTHORIZE);
        } else {
            $stateMachine->apply($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_PAY);
        }
        $entityManager->flush();

        return $order;
    }

    /** The SKU comes from this distributor only (products are reused between tests). */
    private static function onlySource(ProductVariant $variant, string $distributor, Fulfilment $fulfilment): void
    {
        foreach ($variant->getSources()->toArray() as $source) {
            if ($source->getSupplier() !== $distributor) {
                $variant->removeSource($source);
            }
        }
        $source = $variant->sourceFor($distributor);
        $source->setFulfilment($fulfilment);
        $source->setEnabled(true);
        $source->setCostInCents(null);
        $source->recordStock(null);
    }

    /** A new payment by an offline method (no Stripe calls), for the order's total. */
    private function payment(OrderInterface $order, Channel $channel): PaymentInterface
    {
        $entityManager = $this->entityManager();
        $method = $entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'e2e_offline']);
        if (!$method instanceof PaymentMethod) {
            $factory = static::getContainer()->get('sylius.factory.payment_method');
            self::assertInstanceOf(PaymentMethodFactoryInterface::class, $factory);
            $method = $factory->createWithGateway('offline');
            self::assertInstanceOf(PaymentMethod::class, $method);
            $method->setCode('e2e_offline');
            $method->setCurrentLocale('en_US');
            $method->setFallbackLocale('en_US');
            $method->setName('Offline');
            $method->getGatewayConfig()?->setGatewayName('offline');
            $method->addChannel($channel);
            $entityManager->persist($method);
        }

        $factory = static::getContainer()->get('sylius.factory.payment');
        self::assertInstanceOf(FactoryInterface::class, $factory);
        $payment = $factory->createNew();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($method);
        $payment->setAmount(max(1, $order->getTotal()));
        $payment->setCurrencyCode('USD');
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);
        $entityManager->flush();

        return $payment;
    }

    private function shippingMethod(Channel $channel): ShippingMethodInterface
    {
        $entityManager = $this->entityManager();
        $method = $entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'e2e_standard']);
        if ($method instanceof ShippingMethodInterface) {
            return $method;
        }

        $zone = $entityManager->getRepository(Zone::class)->findOneBy(['code' => 'US']);
        self::assertInstanceOf(Zone::class, $zone, 'Load the "e2e" fixture suite first.');
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

        return $method;
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.distributor');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function fakeDistributor(): FakeDistributor
    {
        $registry = static::getContainer()->get(DistributorRegistry::class);
        self::assertInstanceOf(DistributorRegistry::class, $registry);
        $fake = $registry->get(FakeDistributor::CODE);
        self::assertInstanceOf(FakeDistributor::class, $fake);

        return $fake;
    }

    private function handler(): SubmitPurchaseOrderHandler
    {
        $handler = static::getContainer()->get(SubmitPurchaseOrderHandler::class);
        self::assertInstanceOf(SubmitPurchaseOrderHandler::class, $handler);

        return $handler;
    }

    private function loginAsAdmin(): void
    {
        $repository = static::getContainer()->get('sylius.repository.admin_user');
        self::assertInstanceOf(UserRepositoryInterface::class, $repository);
        $admin = $repository->findOneBy(['username' => 'admin']);
        self::assertInstanceOf(AdminUserInterface::class, $admin, 'Load the "e2e" fixture suite first.');

        $this->client->loginUser($admin, 'admin');
    }
}
