<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow exchange-rate history for repeated currency pairs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate DROP CONSTRAINT IF EXISTS UNIQ_EXCHANGE_RATE_CURRENCIES');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate ADD CONSTRAINT UNIQ_EXCHANGE_RATE_CURRENCIES UNIQUE (base_currency_id, target_currency_id)');
    }
}
