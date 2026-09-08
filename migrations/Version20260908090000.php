<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make exchange-rate pairs unique and preserve API precision.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate ALTER COLUMN rate TYPE NUMERIC(20, 10)');
        $this->addSql('ALTER TABLE exchange_rate ADD CONSTRAINT UNIQ_EXCHANGE_RATE_CURRENCIES UNIQUE (base_currency_id, target_currency_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate DROP CONSTRAINT UNIQ_EXCHANGE_RATE_CURRENCIES');
        $this->addSql('ALTER TABLE exchange_rate ALTER COLUMN rate TYPE NUMERIC(10, 4)');
    }
}
