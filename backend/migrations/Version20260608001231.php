<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608001231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin 2FA: add users.last_totp_step for TOTP replay protection (single-use codes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_totp_step INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP last_totp_step');
    }
}
