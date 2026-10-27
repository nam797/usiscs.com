<?php

declare(strict_types=1);

namespace App\Repository\Procurement;

use App\Distributor\PurchaseOrderState;
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
}
