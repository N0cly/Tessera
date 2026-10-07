<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Link.demo_seeded: marks the links planted by DemoWorkspaceSeeder, the only
 * ones allowed a real 302 in demo mode (tessera-demo-real-redirects.md).
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Link.demo_seeded (demo real-redirect allowlist).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD demo_seeded BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP demo_seeded');
    }
}
