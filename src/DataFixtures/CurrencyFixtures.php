<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Currency;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class CurrencyFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $currencies = [
            ['name' => 'Ariary', 'code' => 'MGA', 'symbol' => 'Ar'],
            ['name' => 'Euro', 'code' => 'EUR', 'symbol' => '€'],
            ['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$'],
            ['name' => 'British Pound', 'code' => 'GBP', 'symbol' => '£'],
            ['name' => 'Japanese Yen', 'code' => 'JPY', 'symbol' => '¥'],
            ['name' => 'Swiss Franc', 'code' => 'CHF', 'symbol' => 'CHF'],
            ['name' => 'Canadian Dollar', 'code' => 'CAD', 'symbol' => '$'],
            ['name' => 'Australian Dollar', 'code' => 'AUD', 'symbol' => '$'],
            ['name' => 'Chinese Yuan', 'code' => 'CNY', 'symbol' => '¥'],
            ['name' => 'Indian Rupee', 'code' => 'INR', 'symbol' => '₹'],
        ];

        foreach ($currencies as $data) {
            $currency = (new Currency())
                ->setName($data['name'])
                ->setCode($data['code'])
                ->setSymbol($data['symbol']);

            $manager->persist($currency);
            $this->addReference('currency_'.$data['code'], $currency);
        }

        $manager->flush();
    }
}
