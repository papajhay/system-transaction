<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class FeeFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // The Laravel FeeSeeder does not define any fee records.
    }
}
