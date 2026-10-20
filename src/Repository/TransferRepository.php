<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    /**
     * @return list<Transfer>
     */
    public function findPendingOlderThan(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('transfer')
            ->andWhere('transfer.status = :status')
            ->andWhere('transfer.createdAt < :cutoff')
            ->setParameter('status', StatusTransfer::PENDING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('transfer.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
