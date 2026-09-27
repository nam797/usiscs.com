<?php

declare(strict_types=1);

namespace App\Repository\Rfq;

use App\Entity\Rfq\RfqRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RfqRequest>
 */
class RfqRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RfqRequest::class);
    }
}
