<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601112532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scans (id UUID NOT NULL, scanned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, country VARCHAR(2) DEFAULT NULL, device VARCHAR(32) DEFAULT NULL, os VARCHAR(32) DEFAULT NULL, referrer TEXT DEFAULT NULL, link_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2AA4F257ADA40271 ON scans (link_id)');
        $this->addSql('CREATE INDEX idx_scan_link_time ON scans (link_id, scanned_at)');
        $this->addSql('ALTER TABLE scans ADD CONSTRAINT FK_2AA4F257ADA40271 FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scans DROP CONSTRAINT FK_2AA4F257ADA40271');
        $this->addSql('DROP TABLE scans');
    }
}
