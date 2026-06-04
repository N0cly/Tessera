<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604201738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fallback milestone: add Link.fallback_url (nullable) for lapsed-subscription redirects.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD fallback_url TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP fallback_url');
    }
}
