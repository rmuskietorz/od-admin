<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'login_attempt audit table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE login_attempt (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                username VARCHAR(180) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                success BOOLEAN NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_attempt_created_at ON login_attempt (created_at)');
        $this->addSql('CREATE INDEX idx_attempt_username ON login_attempt (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_attempt');
    }
}
