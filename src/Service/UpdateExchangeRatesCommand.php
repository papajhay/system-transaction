<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Repository\ExchangeRateRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:exchange-rates:update',
    description: 'Fetch and persist the latest exchange rates for every currency pair.',
)]
final class UpdateExchangeRatesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateProvider $exchangeRateProvider,
        private readonly ExchangeRateRepository $exchangeRateRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<Currency> $currencies */
        $currencies = $this->entityManager
            ->getRepository(Currency::class)
            ->findAll();
        $now = new DateTimeImmutable();
        $updatedCount = 0;

        foreach ($currencies as $baseCurrency) {
            $rates = $this->exchangeRateProvider->getRatesForBaseCurrency($baseCurrency);

            foreach ($currencies as $targetCurrency) {
                if ($baseCurrency === $targetCurrency) {
                    continue;
                }

                $rate = $rates[strtoupper($targetCurrency->getCode())] ?? null;
                if ($rate === null) {
                    $output->writeln(sprintf(
                        '<comment>Skipping missing rate %s/%s.</comment>',
                        $baseCurrency->getCode(),
                        $targetCurrency->getCode(),
                    ));
                    continue;
                }

                $exchangeRate = $this->exchangeRateRepository->findOneByCurrencyPair($baseCurrency, $targetCurrency);
                if ($exchangeRate === null) {
                    $exchangeRate = (new ExchangeRate())
                        ->setBaseCurrency($baseCurrency)
                        ->setTargetCurrency($targetCurrency)
                        ->setCreatedAt($now);
                    $this->entityManager->persist($exchangeRate);
                }

                $exchangeRate
                    ->setRate($rate)
                    ->setUpdatedAt($now);
                ++$updatedCount;
            }
        }

        $this->entityManager->flush();
        $output->writeln(sprintf('<info>Synchronized %d exchange rates.</info>', $updatedCount));

        return Command::SUCCESS;
    }
}
