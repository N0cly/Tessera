<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607234428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin panel: add users.totp_secret (admin 2FA) and the admin_audit_logs table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_audit_logs (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor_email VARCHAR(180) NOT NULL, action VARCHAR(64) NOT NULL, success BOOLEAN NOT NULL, ip VARCHAR(45) DEFAULT NULL, detail JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_audit_created_at ON admin_audit_logs (created_at)');
        $this->addSql('ALTER TABLE users ADD totp_secret VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_audit_logs');
        $this->addSql('ALTER TABLE users DROP totp_secret');
    }
}
