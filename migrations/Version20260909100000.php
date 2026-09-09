<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable transfer fee fields and allow fee configurations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE fees SET type = 'fee charged_fixed' WHERE type = 'fee charged'");
        $this->addSql('ALTER TABLE fees ADD name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE fees ADD rate DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE fees ALTER amount TYPE DOUBLE PRECISION USING amount::DOUBLE PRECISION');
        $this->addSql('ALTER TABLE fees ALTER amount SET DEFAULT 0');
        $this->addSql('ALTER TABLE fees ALTER transfer_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE fees SET type = 'fee charged' WHERE type IN ('fee charged_fixed', 'fee charged_rate')");
        $this->addSql('ALTER TABLE fees ALTER transfer_id SET NOT NULL');
        $this->addSql("ALTER TABLE fees ALTER amount TYPE NUMERIC(10, 2) USING amount::NUMERIC(10, 2)");
        $this->addSql("ALTER TABLE fees ALTER amount SET DEFAULT '0.00'");
        $this->addSql('ALTER TABLE fees DROP name');
        $this->addSql('ALTER TABLE fees DROP rate');
    }
}
