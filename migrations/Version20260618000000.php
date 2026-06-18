<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '2FA: totp_secret + recovery_codes auf app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN totp_secret VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD COLUMN recovery_codes CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN totp_secret');
        $this->addSql('ALTER TABLE app_user DROP COLUMN recovery_codes');
    }
}
