<?php

declare(strict_types=1);

namespace App\Tests\Unit\Distributor;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\PurchaseOrderStateMachine;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Order\OrderItemUnit;
use App\Entity\Product\ProductVariant;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;

/** The real "purchase_order" state machine (from config/packages/workflow.yaml) and order fixtures, without a kernel. */
trait PurchaseOrderTestHelpers
{
    /** @return array{places: list<string>, transitions: array<string, array{from: string|list<string>, to: string}>, initial_marking: string} */
    private static function workflowConfig(): array
    {
        /** @var array{framework: array{workflows: array{purchase_order: array{places: list<string>, transitions: array<string, array{from: string|list<string>, to: string}>, initial_marking: string}}}} $config */
        $config = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/workflow.yaml');

        return $config['framework']['workflows']['purchase_order'];
    }

    private static function stateMachine(): PurchaseOrderStateMachine
    {
        $config = self::workflowConfig();
        $builder = new DefinitionBuilder($config['places']);
        $builder->setInitialPlaces($config['initial_marking']);
        foreach ($config['transitions'] as $name => $transition) {
            foreach ((array) $transition['from'] as $from) {
                $builder->addTransition(new Transition($name, $from, $transition['to']));
            }
        }

        return new PurchaseOrderStateMachine(new StateMachine($builder->build(), new MethodMarkingStore(true, 'state'), null, 'purchase_order'));
    }

    /** @param list<array{code: string, distributor: ?string, fulfilment: Fulfilment, quantity?: int}> $lines */
    private static function order(string $number, array $lines): Order
    {
        $order = new Order();
        $order->setNumber($number);
        foreach ($lines as $line) {
            $variant = new ProductVariant();
            $variant->setCode($line['code']);
            $variant->setCurrentLocale('en_US');
            $variant->setFallbackLocale('en_US');
            $variant->setDistributor($line['distributor']);
            $variant->setFulfilment($line['fulfilment']);

            $item = new OrderItem();
            $item->setVariant($variant);
            $item->setProductName('Glove '.$line['code']);
            $item->setUnitPrice(1299);
            for ($i = 0; $i < ($line['quantity'] ?? 1); ++$i) {
                new OrderItemUnit($item);
            }
            $order->addItem($item);
        }

        return $order;
    }
}
