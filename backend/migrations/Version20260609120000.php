<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop Link.fallback_url: the lapsed-subscription fallback / inactive-page
 * mechanism is removed — the redirect now always serves the destination.
 */
final class Version20260609120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop Link.fallback_url (lapsed-subscription fallback removed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP fallback_url');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD fallback_url TEXT DEFAULT NULL');
    }
}
