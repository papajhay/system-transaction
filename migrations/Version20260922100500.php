<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922100500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add business date and daily uniqueness to exchange rates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate ADD rate_date DATE DEFAULT NULL');
        $this->addSql('UPDATE exchange_rate SET rate_date = CAST(created_at AS DATE) WHERE rate_date IS NULL');
        $this->addSql('ALTER TABLE exchange_rate ALTER COLUMN rate_date SET NOT NULL');
        $this->addSql('ALTER TABLE exchange_rate ADD CONSTRAINT UNIQ_EXCHANGE_RATE_PAIR_RATE_DATE UNIQUE (base_currency_id, target_currency_id, rate_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate DROP CONSTRAINT UNIQ_EXCHANGE_RATE_PAIR_RATE_DATE');
        $this->addSql('ALTER TABLE exchange_rate DROP COLUMN rate_date');
    }
}
