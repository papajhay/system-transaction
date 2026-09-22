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
            ->orderBy('exchangeRate.rateDate', 'DESC')
            ->addOrderBy('exchangeRate.createdAt', 'DESC')
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

    public function findOneByCurrencyPairAndRateDate(
        Currency $baseCurrency,
        Currency $targetCurrency,
        DateTimeImmutable $date,
    ): ?ExchangeRate {
        $start = $date->setTime(0, 0, 0);

        return $this->createQueryBuilder('exchangeRate')
            ->andWhere('exchangeRate.baseCurrency = :baseCurrency')
            ->andWhere('exchangeRate.targetCurrency = :targetCurrency')
            ->andWhere('exchangeRate.rateDate = :rateDate')
            ->setParameter('baseCurrency', $baseCurrency)
            ->setParameter('targetCurrency', $targetCurrency)
            ->setParameter('rateDate', $start, Types::DATE_IMMUTABLE)
            ->orderBy('exchangeRate.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasRateForDate(DateTimeImmutable $date): bool
    {
        $start = $date->setTime(0, 0, 0);

        return (bool) $this->createQueryBuilder('exchangeRate')
            ->select('1')
            ->andWhere('exchangeRate.rateDate = :rateDate')
            ->setParameter('rateDate', $start, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
