<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Currency;
use App\Enum\StatusAccount;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class AccountFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $now = new DateTimeImmutable();

        $systemAccounts = [
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac80', 'currency' => 'MGA'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac81', 'currency' => 'EUR'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac82', 'currency' => 'USD'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac83', 'currency' => 'GBP'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac84', 'currency' => 'JPY'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac85', 'currency' => 'CHF'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac86', 'currency' => 'CAD'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac87', 'currency' => 'AUD'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac88', 'currency' => 'CNY'],
            ['number' => 'd31767d8-544f-4e3f-bdee-fb12a718ac89', 'currency' => 'INR'],
        ];

        foreach ($systemAccounts as $data) {
            $currency = $this->getReference(
                'currency_' . $data['currency'],
                Currency::class
            );

            $account = (new Account())
                ->setAccountNumber($data['number'])
                ->setBalance('0.00')
                ->setStatus(StatusAccount::ACTIVE)
                ->setCurrency($currency)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($account);
        }

        $statuses = [
            StatusAccount::ACTIVE,
            StatusAccount::SUSPENDED,
            StatusAccount::CLOSED,
        ];

        // 20 comptes supplémentaires
        for ($i = 0; $i < 20; ++$i) {
            $currencyCodes = [
                'MGA',
                'EUR',
                'USD',
                'GBP',
                'JPY',
                'CHF',
                'CAD',
                'AUD',
                'CNY',
                'INR',
            ];

            $currencyCode = $currencyCodes[array_rand($currencyCodes)];

            $currency = $this->getReference(
                'currency_' . $currencyCode,
                Currency::class
            );

            $account = (new Account())
                ->setAccountNumber($this->uuid())
                ->setBalance(
                    number_format(
                        random_int(0, 1_000_000) / 100,
                        2,
                        '.',
                        ''
                    )
                )
                ->setStatus(
                    $statuses[random_int(0, count($statuses) - 1)]
                )
                ->setCurrency($currency)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($account);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CurrencyFixtures::class,
        ];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }

}
