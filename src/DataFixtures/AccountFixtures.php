<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Enum\StatusAccount;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AccountFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $now = new DateTimeImmutable();

        $systemAccounts = [
            'd31767d8-544f-4e3f-bdee-fb12a718ac80',
            'd31767d8-544f-4e3f-bdee-fb12a718ac81',
            'd31767d8-544f-4e3f-bdee-fb12a718ac82',
            'd31767d8-544f-4e3f-bdee-fb12a718ac83',
            'd31767d8-544f-4e3f-bdee-fb12a718ac84',
            'd31767d8-544f-4e3f-bdee-fb12a718ac85',
            'd31767d8-544f-4e3f-bdee-fb12a718ac86',
            'd31767d8-544f-4e3f-bdee-fb12a718ac87',
            'd31767d8-544f-4e3f-bdee-fb12a718ac88',
            'd31767d8-544f-4e3f-bdee-fb12a718ac89',
        ];

        foreach ($systemAccounts as $accountNumber) {
            $account = new Account();
            $account
                ->setAccountNumber($accountNumber)
                ->setBalance('0.00')
                ->setStatus(StatusAccount::ACTIVE)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($account);
        }

        $statuses = [
            StatusAccount::ACTIVE,
            StatusAccount::SUSPENDED,
            StatusAccount::CLOSED,
        ];

        for ($i = 0; $i < 20; ++$i) {
            $account = new Account();
            $account
                ->setAccountNumber($this->uuid())
                ->setBalance(number_format(random_int(0, 1_000_000) / 100, 2, '.', ''))
                ->setStatus($statuses[random_int(0, count($statuses) - 1)])
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($account);
        }

        $manager->flush();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
