<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\SentEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SentEmail>
 */
class SentEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SentEmail::class);
    }

    public function findOneByMessageId(string $messageId): ?SentEmail
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }
}
