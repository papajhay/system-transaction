<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExchangeRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    public function getExchangeRateFromAndToCurrency(float $fromCurrencyId, float $toCurrencyId): ?ExchangeRate
    {
        return $this->createQueryBuilder('exchangeRate')
            ->andWhere('IDENTITY(exchangeRate.baseCurrency) = :baseCurrencyId')
            ->andWhere('IDENTITY(exchangeRate.targetCurrency) = :targetCurrencyId')
            ->setParameter('baseCurrencyId', $toCurrencyId)
            ->setParameter('targetCurrencyId', $fromCurrencyId)
            ->orderBy('exchangeRate.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
