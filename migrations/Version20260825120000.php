<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the Account table with the Laravel accounts schema without losing data';
    }

    public function up(Schema $schema): void
    {
        // Rename the existing table in place so its rows and all referencing
        // foreign keys remain intact.
        $this->addSql('ALTER TABLE account RENAME TO accounts');

        $this->addSql("CREATE TYPE account_type AS ENUM ('user', 'system')");
        $this->addSql("CREATE TYPE account_status AS ENUM ('active', 'suspended', 'closed')");

        $this->addSql("ALTER TABLE accounts ADD type account_type DEFAULT 'user'::account_type NOT NULL");
        $this->addSql('ALTER TABLE accounts ADD system_name VARCHAR(255) DEFAULT NULL');

        $this->addSql("ALTER TABLE accounts ALTER balance TYPE NUMERIC(15, 2) USING balance::NUMERIC(15, 2)");
        $this->addSql("ALTER TABLE accounts ALTER balance SET DEFAULT '0'");

        $this->addSql('ALTER TABLE accounts ALTER status DROP DEFAULT');
        $this->addSql("ALTER TABLE accounts ALTER status TYPE account_status USING status::text::account_status");
        $this->addSql("ALTER TABLE accounts ALTER status SET DEFAULT 'active'::account_status");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE accounts ALTER status TYPE VARCHAR(255) USING status::text');
        $this->addSql('ALTER TABLE accounts ALTER status SET DEFAULT \'active\'');

        $this->addSql('ALTER TABLE accounts ALTER balance TYPE NUMERIC(10, 2) USING balance::NUMERIC(10, 2)');
        $this->addSql("ALTER TABLE accounts ALTER balance SET DEFAULT '0.00'");
        $this->addSql('ALTER TABLE accounts DROP type');
        $this->addSql('ALTER TABLE accounts DROP system_name');

        $this->addSql('DROP TYPE account_status');
        $this->addSql('DROP TYPE account_type');
        $this->addSql('ALTER TABLE accounts RENAME TO account');
    }
}
