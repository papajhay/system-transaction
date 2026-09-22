<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Repository\ExchangeRateRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class OnDemandExchangeRateService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateProvider $exchangeRateProvider,
        private readonly ExchangeRateRepository $exchangeRateRepository,
    ) {
    }

    public function getRate(Currency $baseCurrency, Currency $targetCurrency): ?ExchangeRate
    {
        $now = new DateTimeImmutable();
        $todayRate = $this->exchangeRateRepository->findOneByCurrencyPairAndRateDate(
            $baseCurrency,
            $targetCurrency,
            $now,
        );

        try {
            $rate = $this->exchangeRateProvider->getRatesForBaseCurrency($baseCurrency)[strtoupper($targetCurrency->getCode())] ?? null;
        } catch (\Throwable $exception) {
            if ($todayRate !== null) {
                return $todayRate;
            }

            throw $exception;
        }

        if ($rate === null) {
            return null;
        }

        if ($todayRate === null) {
            $todayRate = (new ExchangeRate())
                ->setBaseCurrency($baseCurrency)
                ->setTargetCurrency($targetCurrency)
                ->setRateDate($now)
                ->setCreatedAt($now);
            $this->entityManager->persist($todayRate);
        }

        $todayRate
            ->setRate($rate)
            ->setUpdatedAt($now);
        $this->entityManager->flush();

        return $todayRate;
    }
}
