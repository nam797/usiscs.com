<?php

declare(strict_types=1);

namespace App\Distributor\EventListener;

use App\Distributor\Message\SubmitPurchaseOrder;
use App\Entity\Procurement\PurchaseOrder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Puts a purchase order on the worker queue once the flush that made it
 * queued (created, retried, resumed) has saved it, so the worker never sees
 * a message for a row that isn't there. The queue is a table in the same
 * database, so inside an outer transaction (Sylius's payment handlers) the
 * message is committed or rolled back together with the order.
 *
 * If sending fails, dispatched_at stays empty and app:distributor:dispatch
 * sends it on its next run.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PurchaseOrderOutbox
{
    /** @var array<int, PurchaseOrder> */
    private array $toDispatch = [];

    public function __construct(
        private readonly MessageBusInterface $appDistributorBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof PurchaseOrder && $entity->isWaitingForDispatch()) {
                $this->toDispatch[spl_object_id($entity)] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $purchaseOrders = $this->toDispatch;
        $this->toDispatch = [];

        $entityManager = $args->getObjectManager();
        foreach ($purchaseOrders as $purchaseOrder) {
            $id = $purchaseOrder->getId();
            if (null === $id || !$purchaseOrder->isWaitingForDispatch()) {
                continue;
            }

            try {
                $this->appDistributorBus->dispatch(new SubmitPurchaseOrder($id));

                // Not a flush (we're inside one): write the column directly
                // and tell the unit of work it's saved.
                $now = new \DateTimeImmutable();
                $entityManager->getConnection()->executeStatement(
                    'UPDATE app_purchase_order SET dispatched_at = ? WHERE id = ?',
                    [$now, $id],
                    [Types::DATETIME_IMMUTABLE, Types::INTEGER],
                );
                $purchaseOrder->setDispatchedAt($now);
                $entityManager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($purchaseOrder), 'dispatchedAt', $now);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf('Purchase order %s: could not queue it (app:distributor:dispatch will retry): %s', $purchaseOrder->getNumber(), $exception->getMessage()));
            }
        }
    }
}
