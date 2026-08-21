<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Enum\Role;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixture extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $now = new DateTimeImmutable();
        $password = 'password';

        for ($i = 0; $i < 2; ++$i) {
            $user = (new User())
                ->setName($faker->name())
                ->setEmail($faker->unique()->regexify('[A-Za-z0-9]{10}') . '@example.com')
                ->setEmailVerifiedAt($now)
                ->setRole(random_int(0, 1) === 1 ? Role::ADMIN : Role::USER)
                ->setRememberToken($faker->regexify('[A-Za-z0-9]{10}'))
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $manager->persist($user);
        }

        $manager->flush();
    }
}
