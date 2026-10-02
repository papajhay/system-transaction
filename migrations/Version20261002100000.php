<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the account status used before suspension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ADD previous_status account_status DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP previous_status');
    }
}
