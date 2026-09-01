<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename ledger entries to conversions without losing data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ledger_entries RENAME TO conversions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversions RENAME TO ledger_entries');
    }
}
