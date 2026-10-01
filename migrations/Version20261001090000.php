<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store transfer fee type, rate, and amount.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE transfer ADD fee_type VARCHAR(255) DEFAULT 'free charged' NOT NULL");
        $this->addSql('ALTER TABLE transfer ADD fee_rate DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE transfer ADD fee_amount DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transfer DROP fee_type');
        $this->addSql('ALTER TABLE transfer DROP fee_rate');
        $this->addSql('ALTER TABLE transfer DROP fee_amount');
    }
}
