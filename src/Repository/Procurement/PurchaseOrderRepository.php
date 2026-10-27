<?php

declare(strict_types=1);

namespace App\Repository\Procurement;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\PurchaseOrderState;
use App\Distributor\Warehouse\WarehouseStatus;
use Doctrine\ORM\QueryBuilder;
use App\Entity\Procurement\PurchaseOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * @extends ServiceEntityRepository<PurchaseOrder>
 */
class PurchaseOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseOrder::class);
    }

    /** @return list<PurchaseOrder> */
    public function findForOrder(OrderInterface $order): array
    {
        return $this->findBy(['order' => $order], ['id' => 'ASC']);
    }

    /**
     * Queued purchase orders whose message never reached the queue (dispatch
     * failed), or whose message seems lost: dispatched long ago with no
     * attempt since. Retries never wait longer than the retry strategy's
     * max delay (config/packages/messenger.yaml), so $lostAfter must be
     * longer than that.
     *
     * @param list<string> $pausedDistributors left alone; resuming one re-sends its orders
     *
     * @return list<PurchaseOrder>
     */
    public function findStaleQueued(\DateTimeImmutable $undispatchedBefore, \DateTimeImmutable $lostBefore, array $pausedDistributors): array
    {
        $query = $this->createQueryBuilder('p')
            ->where('p.state = :queued')
            ->andWhere('(p.dispatchedAt IS NULL AND p.createdAt < :undispatchedBefore) OR (p.dispatchedAt < :lostBefore AND (p.lastAttemptAt IS NULL OR p.lastAttemptAt < :lostBefore))')
            ->setParameter('queued', PurchaseOrderState::QUEUED)
            ->setParameter('undispatchedBefore', $undispatchedBefore)
            ->setParameter('lostBefore', $lostBefore)
            ->orderBy('p.id');

        if ([] !== $pausedDistributors) {
            $query->andWhere('p.distributor NOT IN (:paused)')->setParameter('paused', $pausedDistributors);
        }

        /** @var list<PurchaseOrder> $result */
        $result = $query->getQuery()->getResult();

        return $result;
    }

    /**
     * Stuck in "sending": the worker died mid-attempt.
     *
     * @return list<PurchaseOrder>
     */
    public function findStuckSubmitting(\DateTimeImmutable $attemptedBefore): array
    {
        /** @var list<PurchaseOrder> $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.state = :submitting')
            ->andWhere('p.lastAttemptAt IS NULL OR p.lastAttemptAt < :before')
            ->setParameter('submitting', PurchaseOrderState::SUBMITTING)
            ->setParameter('before', $attemptedBefore)
            ->orderBy('p.id')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<PurchaseOrder> */
    public function findQueuedFor(string $distributor): array
    {
        return $this->findBy(['distributor' => $distributor, 'state' => PurchaseOrderState::QUEUED], ['id' => 'ASC']);
    }

    /**
     * @param list<string> $states
     *
     * @return list<PurchaseOrder>
     */
    public function findInStates(array $states): array
    {
        return $this->findBy(['state' => $states], ['id' => 'ASC']);
    }

    /**
     * Live cross-dock purchase orders, per warehouse status.
     *
     * @return array<string, int> by WarehouseStatus value, every status present
     */
    public function countByWarehouseStatus(\DateTimeImmutable $today): array
    {
        $counts = [];
        foreach (WarehouseStatus::cases() as $status) {
            $query = $status->apply($this->crossDock()->select('COUNT(p.id)'), 'p', 'w_', $today);
            $counts[$status->value] = (int) $query->getQuery()->getSingleScalarResult();
        }

        return $counts;
    }

    /**
     * Not received yet, soonest expected first (overdue at the top), those with no date last.
     *
     * @return list<PurchaseOrder>
     */
    public function findComingIn(int $limit): array
    {
        /** @var list<PurchaseOrder> $result */
        $result = $this->crossDock()
            ->addSelect('CASE WHEN p.expectedAt IS NULL THEN 1 ELSE 0 END AS HIDDEN no_date')
            ->andWhere('p.receivedAt IS NULL AND p.forwardedAt IS NULL')
            ->orderBy('no_date')
            ->addOrderBy('p.expectedAt')
            ->addOrderBy('p.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** Not received, expected between the two dates (inclusive). */
    public function countDueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->crossDock()
            ->select('COUNT(p.id)')
            ->andWhere('p.receivedAt IS NULL AND p.forwardedAt IS NULL')
            ->andWhere('p.expectedAt BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<PurchaseOrder> received at our warehouse, not shipped on yet, oldest first */
    public function findReceivedNotShippedOn(int $limit): array
    {
        /** @var list<PurchaseOrder> $result */
        $result = $this->crossDock()
            ->andWhere('p.receivedAt IS NOT NULL AND p.forwardedAt IS NULL')
            ->orderBy('p.receivedAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @param list<string> $states
     *
     * @return array<string, int> by state, every state present
     */
    public function countInStates(array $states): array
    {
        /** @var list<array{state: string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.state AS state, COUNT(p.id) AS n')
            ->where('p.state IN (:states)')
            ->setParameter('states', $states)
            ->groupBy('p.state')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys($states, 0);
        foreach ($rows as $row) {
            $counts[$row['state']] = (int) $row['n'];
        }

        return $counts;
    }

    /** Live cross-dock purchase orders, as "p". */
    private function crossDock(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->where('p.fulfilment = :cross_dock')
            ->andWhere('p.state <> :cancelled')
            ->setParameter('cross_dock', Fulfilment::CrossDock->value)
            ->setParameter('cancelled', PurchaseOrderState::CANCELLED);
    }
}
