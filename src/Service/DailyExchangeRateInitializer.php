<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Repository\ExchangeRateRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class DailyExchangeRateInitializer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateProvider $exchangeRateProvider,
        private readonly ExchangeRateRepository $exchangeRateRepository,
    ) {
    }

    public function initialize(): void
    {
        $now = new DateTimeImmutable();
        if ($this->exchangeRateRepository->hasRateForDate($now)) {
            return;
        }

        /** @var list<Currency> $currencies */
        $currencies = $this->entityManager
            ->getRepository(Currency::class)
            ->findAll();

        foreach ($currencies as $baseCurrency) {
            $rates = $this->exchangeRateProvider->getRatesForBaseCurrency($baseCurrency);

            foreach ($currencies as $targetCurrency) {
                if ($baseCurrency === $targetCurrency) {
                    continue;
                }

                $rate = $rates[strtoupper($targetCurrency->getCode())] ?? null;
                if ($rate === null) {
                    continue;
                }

                $this->entityManager->persist(
                    (new ExchangeRate())
                        ->setBaseCurrency($baseCurrency)
                        ->setTargetCurrency($targetCurrency)
                        ->setRate($rate)
                        ->setCreatedAt($now)
                        ->setUpdatedAt($now),
                );
            }
        }

        $this->entityManager->flush();
    }
}
