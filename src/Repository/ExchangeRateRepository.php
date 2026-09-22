<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    public function getExchangeRateFromAndToCurrency(int $fromCurrencyId, int $toCurrencyId): ?ExchangeRate
    {
        
        return $this->createQueryBuilder('exchangeRate')
            ->andWhere('IDENTITY(exchangeRate.baseCurrency) = :baseCurrencyId')
            ->andWhere('IDENTITY(exchangeRate.targetCurrency) = :targetCurrencyId')
            ->setParameter('baseCurrencyId', $fromCurrencyId)
            ->setParameter('targetCurrencyId', $toCurrencyId)
            ->orderBy('exchangeRate.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByCurrencyPair(Currency $baseCurrency, Currency $targetCurrency): ?ExchangeRate
    {
        return $this->findOneBy([
            'baseCurrency' => $baseCurrency,
            'targetCurrency' => $targetCurrency,
        ]);
    }

    public function hasRateForDate(DateTimeImmutable $date): bool
    {
        $start = $date->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        return (bool) $this->createQueryBuilder('exchangeRate')
            ->select('1')
            ->andWhere('exchangeRate.createdAt >= :start')
            ->andWhere('exchangeRate.createdAt < :end')
            ->setParameter('start', $start, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $end, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
