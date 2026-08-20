<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class ExchangeRateFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $now = new DateTimeImmutable();
        $exchangeRates = [
            ['base' => 'MGA', 'target' => 'EUR', 'rate' => '4870'],
            ['base' => 'MGA', 'target' => 'USD', 'rate' => '4145'],
            ['base' => 'MGA', 'target' => 'GBP', 'rate' => '5600'],
            ['base' => 'MGA', 'target' => 'JPY', 'rate' => '26'],
            ['base' => 'MGA', 'target' => 'CHF', 'rate' => '5300'],
            ['base' => 'MGA', 'target' => 'CAD', 'rate' => '3040'],
            ['base' => 'MGA', 'target' => 'AUD', 'rate' => '2970'],
            ['base' => 'MGA', 'target' => 'CNY', 'rate' => '630'],
            ['base' => 'MGA', 'target' => 'INR', 'rate' => '44'],
            ['base' => 'EUR', 'target' => 'MGA', 'rate' => '0.000204'],
            ['base' => 'EUR', 'target' => 'USD', 'rate' => '0.93'],
            ['base' => 'EUR', 'target' => 'GBP', 'rate' => '1.17'],
            ['base' => 'EUR', 'target' => 'JPY', 'rate' => '0.0062'],
            ['base' => 'EUR', 'target' => 'CHF', 'rate' => '1.04'],
            ['base' => 'EUR', 'target' => 'CAD', 'rate' => '0.68'],
            ['base' => 'EUR', 'target' => 'AUD', 'rate' => '0.61'],
            ['base' => 'EUR', 'target' => 'CNY', 'rate' => '0.13'],
            ['base' => 'EUR', 'target' => 'INR', 'rate' => '0.011'],
            ['base' => 'USD', 'target' => 'MGA', 'rate' => '0.00024'],
            ['base' => 'USD', 'target' => 'EUR', 'rate' => '1.08'],
            ['base' => 'USD', 'target' => 'GBP', 'rate' => '1.26'],
            ['base' => 'USD', 'target' => 'JPY', 'rate' => '0.0067'],
            ['base' => 'USD', 'target' => 'CHF', 'rate' => '1.12'],
            ['base' => 'USD', 'target' => 'CAD', 'rate' => '0.73'],
            ['base' => 'USD', 'target' => 'AUD', 'rate' => '0.66'],
            ['base' => 'USD', 'target' => 'CNY', 'rate' => '0.14'],
            ['base' => 'USD', 'target' => 'INR', 'rate' => '0.012'],
        ];

        $currencies = [];
        foreach (['MGA', 'EUR', 'USD', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'CNY', 'INR'] as $code) {
            $currencies[$code] = $this->getReference('currency_' . $code, Currency::class);
        }

        $repository = $manager->getRepository(ExchangeRate::class);

        foreach ($exchangeRates as $data) {
            $baseCurrency = $currencies[$data['base']];
            $targetCurrency = $currencies[$data['target']];
            $exchangeRate = $repository->findOneBy([
                'baseCurrency' => $baseCurrency,
                'targetCurrency' => $targetCurrency,
            ]) ?? new ExchangeRate();

            $exchangeRate
                ->setBaseCurrency($baseCurrency)
                ->setTargetCurrency($targetCurrency)
                ->setRate($data['rate'])
                ->setCreatedAt($exchangeRate->getId() === null ? $now : $exchangeRate->getCreatedAt())
                ->setUpdatedAt($now);

            $manager->persist($exchangeRate);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CurrencyFixtures::class,
        ];
    }
}
