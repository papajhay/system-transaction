<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260902090701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accounts ALTER status TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE accounts ALTER type TYPE VARCHAR(255)');
        $this->addSql('ALTER INDEX uniq_7d3656a4b1a4d127 RENAME TO UNIQ_CAC89EACB1A4D127');
        $this->addSql('ALTER INDEX idx_7d3656a438248176 RENAME TO IDX_CAC89EAC38248176');
        $this->addSql('ALTER INDEX idx_e3fd73f4537048af RENAME TO IDX_6A02DBA5537048AF');
        $this->addSql('ALTER INDEX idx_e3fd73f4a66bb013 RENAME TO IDX_6A02DBA5A66BB013');
        $this->addSql('ALTER INDEX idx_e3fd73f416b7bf15 RENAME TO IDX_6A02DBA516B7BF15');
        $this->addSql('ALTER TABLE transfer ADD received_amount NUMERIC(20, 2) NOT NULL');
        $this->addSql('ALTER TABLE transfer ADD exchange_rate NUMERIC(20, 10) NOT NULL');
        $this->addSql('ALTER TABLE transfer ADD received_currency_id INT NOT NULL');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT FK_4034A3C0CE3D2663 FOREIGN KEY (received_currency_id) REFERENCES currency (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_4034A3C0CE3D2663 ON transfer (received_currency_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accounts ALTER type TYPE VARCHAR');
        $this->addSql('ALTER TABLE accounts ALTER status TYPE VARCHAR');
        $this->addSql('ALTER INDEX idx_cac89eac38248176 RENAME TO idx_7d3656a438248176');
        $this->addSql('ALTER INDEX uniq_cac89eacb1a4d127 RENAME TO uniq_7d3656a4b1a4d127');
        $this->addSql('ALTER INDEX idx_6a02dba5537048af RENAME TO idx_e3fd73f4537048af');
        $this->addSql('ALTER INDEX idx_6a02dba516b7bf15 RENAME TO idx_e3fd73f416b7bf15');
        $this->addSql('ALTER INDEX idx_6a02dba5a66bb013 RENAME TO idx_e3fd73f4a66bb013');
        $this->addSql('ALTER TABLE transfer DROP CONSTRAINT FK_4034A3C0CE3D2663');
        $this->addSql('DROP INDEX IDX_4034A3C0CE3D2663');
        $this->addSql('ALTER TABLE transfer DROP received_amount');
        $this->addSql('ALTER TABLE transfer DROP exchange_rate');
        $this->addSql('ALTER TABLE transfer DROP received_currency_id');
    }
}
