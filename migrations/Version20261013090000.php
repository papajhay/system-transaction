<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the obsolete fee name column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fees DROP name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fees ADD name VARCHAR(255) DEFAULT NULL');
    }
}
