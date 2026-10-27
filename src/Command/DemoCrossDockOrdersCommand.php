<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\FulfilmentRoute;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Entity\Addressing\Address;
use App\Entity\Channel\Channel;
use App\Entity\Customer\Customer;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Order\OrderItemUnit;
use App\Entity\Procurement\PurchaseOrder;
use App\Entity\Procurement\PurchaseOrderLine;
use App\Entity\Product\ProductVariant;
use App\Entity\Shipping\Shipment;
use App\Entity\Shipping\ShippingMethod;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Dev only: sample paid orders, DEMO-001 to DEMO-006, whose purchase orders
 * cover every warehouse state (overdue, due soon, on its way, not shipped
 * yet, received, shipped on) plus a direct one awaiting manual entry, so
 * the backoffice dashboard, the Warehouse list and the Sylius order list
 * have something to show.
 *
 * Built directly, not through payment and the worker: nothing is queued
 * or emailed, and no catalog data changes (it only reads priced variants,
 * ORS ones first). Running it again replaces the DEMO orders; --delete
 * removes them.
 */
#[AsCommand(
    name: 'app:dev:demo-cross-dock',
    description: 'Dev only: create sample DEMO-xxx orders with direct and cross-dock purchase orders in every warehouse state.',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
final class DemoCrossDockOrdersCommand extends Command
{
    private const PREFIX = 'DEMO-';

    private const PEOPLE = [
        ['Maria', 'Lopez', 'Lopez Mechanical', 'Houston', 'US-TX', '77002'],
        ['Dan', 'Whitaker', 'Whitaker Electric', 'Tulsa', 'US-OK', '74103'],
        ['Priya', 'Shah', 'Gulf Coast Fabrication', 'Mobile', 'US-AL', '36602'],
        ['Tom', 'Becker', 'Becker Site Services', 'Omaha', 'US-NE', '68102'],
        ['Alicia', 'Nguyen', 'Nguyen Builders', 'Denver', 'US-CO', '80202'],
        ['Sam', 'Ortiz', 'Ortiz Roofing', 'Phoenix', 'US-AZ', '85004'],
    ];

    private const SHIPPED = [PurchaseOrderState::SUBMITTED, PurchaseOrderState::ACKNOWLEDGED, PurchaseOrderState::SHIPPED];

    private const EVENT_FOR_STATE = [
        PurchaseOrderState::SUBMITTED => PurchaseOrderEventType::MarkSubmitted,
        PurchaseOrderState::ACKNOWLEDGED => PurchaseOrderEventType::Acknowledge,
        PurchaseOrderState::SHIPPED => PurchaseOrderEventType::Ship,
        PurchaseOrderState::AWAITING_MANUAL_ENTRY => PurchaseOrderEventType::RequestManualEntry,
    ];

    /** @var list<ProductVariant> */
    private array $variants = [];

    private int $nextVariant = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delete', null, InputOption::VALUE_NONE, 'Only remove the DEMO orders');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $removed = $this->deleteDemoOrders();
        if ($input->getOption('delete')) {
            $io->success(sprintf('Removed %d DEMO order(s).', $removed));

            return Command::SUCCESS;
        }

        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        $method = $this->entityManager->getRepository(ShippingMethod::class)->findOneBy([], ['id' => 'ASC']);
        /** @var list<ProductVariant> $variants */
        $variants = $this->entityManager->getRepository(ProductVariant::class)->createQueryBuilder('v')
            ->addSelect('CASE WHEN v.distributor = :ors THEN 0 ELSE 1 END AS HIDDEN not_ors')
            ->join('v.channelPricings', 'cp')
            ->where('cp.channelCode = :channel AND cp.price > 0')
            ->setParameter('ors', 'ors_nasco')
            ->setParameter('channel', 'default')
            ->orderBy('not_ors')
            ->addOrderBy('v.id')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();
        if (!$channel instanceof Channel || !$method instanceof ShippingMethod || [] === $variants) {
            $io->error('Needs the "default" channel, a shipping method (load the checkout_shipping fixtures) and at least one priced product.');

            return Command::FAILURE;
        }
        $this->variants = $variants;
        $this->nextVariant = 0;

        $today = new \DateTimeImmutable('today');
        /** @var list<array{PurchaseOrder, int}> $shippedDaysAgo when the distributor shipped each */
        $shippedDaysAgo = [];

        // 1. Mixed: the direct part has shipped to the customer; the cross-dock part is due tomorrow.
        $order = $this->order(1, $channel, $method, [4, 2]);
        $crossDock = $this->purchaseOrder($order, 1, Fulfilment::CrossDock, self::SHIPPED, '90811001', [1]);
        $crossDock->addShipment('1Z999AA10000000101', 'UPS GRD COML');
        $crossDock->changeExpectedAt($today->modify('+1 day'));
        $direct = $this->purchaseOrder($order, 2, Fulfilment::OrsDirect, self::SHIPPED, '90811002', [0]);
        $direct->addShipment('1Z999AA10000000102', 'UPS GRD COML');
        $shipment = $order->getShipments()->first();
        if ($shipment instanceof ShipmentInterface) {
            $shipment->setTracking('1Z999AA10000000102');
        }
        $shippedDaysAgo[] = [$crossDock, 2];
        $shippedDaysAgo[] = [$direct, 2];

        // 2. Overdue: shipped a week ago, expected two days ago.
        $order = $this->order(2, $channel, $method, [1, 6]);
        $crossDock = $this->purchaseOrder($order, 1, Fulfilment::CrossDock, self::SHIPPED, '90811003', [0, 1]);
        $crossDock->addShipment('1Z999AA10000000103', 'UPS GRD COML');
        $crossDock->changeExpectedAt($today->modify('-2 days'));
        $shippedDaysAgo[] = [$crossDock, 7];

        // 3. On its way, due in four days.
        $order = $this->order(3, $channel, $method, [10]);
        $crossDock = $this->purchaseOrder($order, 1, Fulfilment::CrossDock, self::SHIPPED, '90811004', [0]);
        $crossDock->addShipment('1Z999AA10000000104', 'FEDEX GROUND');
        $crossDock->changeExpectedAt($today->modify('+4 days'));
        $shippedDaysAgo[] = [$crossDock, 1];

        // 4. The distributor hasn't shipped it yet; no date.
        $order = $this->order(4, $channel, $method, [3, 1]);
        $this->purchaseOrder($order, 1, Fulfilment::CrossDock, [PurchaseOrderState::SUBMITTED, PurchaseOrderState::ACKNOWLEDGED], '90811005', [0, 1]);

        // 5. Received at the warehouse, waiting to ship on.
        $order = $this->order(5, $channel, $method, [2]);
        $crossDock = $this->purchaseOrder($order, 1, Fulfilment::CrossDock, self::SHIPPED, '90811006', [0]);
        $crossDock->addShipment('1Z999AA10000000106', 'UPS GRD COML');
        $crossDock->changeExpectedAt($today->modify('-1 day'));
        $crossDock->markReceived('warehouse@example.test', 'All there.');
        $shippedDaysAgo[] = [$crossDock, 4];

        // 6. Mixed: the cross-dock part has shipped on to the customer; the direct part awaits manual entry.
        $order = $this->order(6, $channel, $method, [5, 1]);
        $crossDock = $this->purchaseOrder($order, 1, Fulfilment::CrossDock, self::SHIPPED, '90811007', [0]);
        $crossDock->addShipment('1Z999AA10000000107', 'UPS GRD COML');
        $crossDock->changeExpectedAt($today->modify('-3 days'));
        $crossDock->markReceived('warehouse@example.test');
        $crossDock->markForwarded('UPS Ground', '1Z999BB20000000201', 'warehouse@example.test');
        $this->purchaseOrder($order, 2, Fulfilment::OrsDirect, [PurchaseOrderState::AWAITING_MANUAL_ENTRY], null, [1]);
        $shippedDaysAgo[] = [$crossDock, 6];

        // None is "queued", and all are marked dispatched, so the outbox sends nothing.
        $this->entityManager->flush();

        // The distributor's tracking arrived N days ago.
        $connection = $this->entityManager->getConnection();
        foreach ($shippedDaysAgo as [$purchaseOrder, $days]) {
            $connection->executeStatement(
                'UPDATE app_purchase_order_shipment SET created_at = ? WHERE purchase_order_id = ?',
                [$today->modify(sprintf('-%d days', $days))->setTime(14, 30)->format('Y-m-d H:i:s'), $purchaseOrder->getId()],
            );
        }

        $io->success(sprintf('Created 6 DEMO orders (%s001 to %s006)%s. See /backoffice and /admin/orders.', self::PREFIX, self::PREFIX, $removed > 0 ? sprintf(', replacing %d', $removed) : ''));

        return Command::SUCCESS;
    }

    private function deleteDemoOrders(): int
    {
        $connection = $this->entityManager->getConnection();
        $ids = [];
        foreach ($connection->fetchFirstColumn('SELECT id FROM sylius_order WHERE number LIKE ?', [self::PREFIX.'%']) as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ([] === $ids) {
            return 0;
        }

        // Purchase orders first (lines, events and tracking cascade in the database); then the orders through Doctrine.
        $connection->executeStatement('DELETE FROM app_purchase_order WHERE order_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        foreach ($ids as $id) {
            $order = $this->entityManager->find(Order::class, $id);
            if (null !== $order) {
                $customer = $order->getCustomer();
                $this->entityManager->remove($order);
                if ($customer instanceof Customer && str_ends_with((string) $customer->getEmail(), '@example.test') && null === $customer->getUser()) {
                    $this->entityManager->remove($customer);
                }
            }
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        return count($ids);
    }

    /** @param list<int> $quantities one item per quantity, from the next variants */
    private function order(int $n, Channel $channel, ShippingMethod $method, array $quantities): Order
    {
        [$first, $last, $company, $city, $province, $postcode] = self::PEOPLE[$n - 1];

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setNumber(sprintf('%s%03d', self::PREFIX, $n));

        $customer = new Customer();
        $customer->setEmail(sprintf('demo-%d@example.test', $n));
        $customer->setFirstName($first);
        $customer->setLastName($last);
        $order->setCustomer($customer);

        foreach (['setShippingAddress', 'setBillingAddress'] as $setter) {
            $address = new Address();
            $address->setFirstName($first);
            $address->setLastName($last);
            $address->setCompany($company);
            $address->setStreet(sprintf('%d Industrial Pkwy', 100 + $n * 7));
            $address->setCity($city);
            $address->setProvinceCode($province);
            $address->setPostcode($postcode);
            $address->setCountryCode('US');
            $order->{$setter}($address);
        }

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $shipment->setState(ShipmentInterface::STATE_READY);
        foreach ($quantities as $quantity) {
            $variant = $this->variants[$this->nextVariant++ % count($this->variants)];
            $item = new OrderItem();
            $item->setVariant($variant);
            $item->setProductName($variant->getProduct()?->getName());
            $item->setVariantName($variant->getName());
            $item->setUnitPrice($variant->getChannelPricingForChannel($channel)?->getPrice() ?? 1000);
            for ($i = 0; $i < $quantity; ++$i) {
                $shipment->addUnit(new OrderItemUnit($item));
            }
            $order->addItem($item);
        }
        $order->addShipment($shipment);

        $order->setState(Order::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime(sprintf('-%d days', 8 - $n)));
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);
        $order->setShippingState(OrderShippingStates::STATE_READY);
        $this->entityManager->persist($order);

        return $order;
    }

    /**
     * @param list<string> $states the places it went through; the last is where it is
     * @param list<int>    $items  which of the order's items it orders
     */
    private function purchaseOrder(Order $order, int $index, Fulfilment $fulfilment, array $states, ?string $reference, array $items): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder($order, sprintf('%s-%d', $order->getNumber(), $index), 'ors_nasco', $fulfilment);
        $purchaseOrder->setDispatchedAt(new \DateTimeImmutable());
        $orderItems = array_values(array_filter($order->getItems()->toArray(), static fn (object $item): bool => $item instanceof OrderItem));
        foreach ($items as $i) {
            $item = $orderItems[$i];
            $code = (string) $item->getVariant()?->getCode();
            $name = trim((string) $item->getProductName().' '.(string) $item->getVariantName());
            new PurchaseOrderLine($purchaseOrder, $item, $code, $code, '' === $name ? $code : $name, $item->getQuantity(), null, $item->getDiscountedUnitPrice(), null);
        }

        $purchaseOrder->log(PurchaseOrderEventType::Created, sprintf('Sample purchase order (app:dev:demo-cross-dock), %s', $fulfilment->label()));
        foreach ($states as $state) {
            if (PurchaseOrderState::SUBMITTED === $state) {
                $purchaseOrder->markSubmitted($reference);
            }
            $purchaseOrder->setState($state);
            $purchaseOrder->log(self::EVENT_FOR_STATE[$state], PurchaseOrderState::SUBMITTED === $state ? 'Distributor order no. '.$reference : null);
        }
        $this->entityManager->persist($purchaseOrder);

        $existing = array_values(array_filter(
            $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions(),
            static fn (object $entity): bool => $entity instanceof PurchaseOrder && $entity->getOrder() === $order,
        ));
        $order->setFulfilmentRoute(FulfilmentRoute::of($existing));

        return $purchaseOrder;
    }
}
