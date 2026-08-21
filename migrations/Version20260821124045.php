<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821124045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_4ce1c7a4537048af RENAME TO IDX_A093C16C537048AF');
        $this->addSql('ALTER TABLE users ALTER role SET DEFAULT \'ROLE_USER\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_a093c16c537048af RENAME TO idx_4ce1c7a4537048af');
        $this->addSql('ALTER TABLE users ALTER role SET DEFAULT \'user\'');
    }
}
