<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608081058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demo mode: add demo_sessions (ephemeral per-session workspace, cascades with its synthetic user).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demo_sessions (id UUID NOT NULL, token VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_98CAA54D5F37A13B ON demo_sessions (token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_98CAA54DA76ED395 ON demo_sessions (user_id)');
        $this->addSql('CREATE INDEX idx_demo_last_activity ON demo_sessions (last_activity_at)');
        $this->addSql('ALTER TABLE demo_sessions ADD CONSTRAINT FK_98CAA54DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE demo_sessions DROP CONSTRAINT FK_98CAA54DA76ED395');
        $this->addSql('DROP TABLE demo_sessions');
    }
}
