<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604195106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing milestone: subscriptions + webhook event ledger (idempotency).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_events (id UUID NOT NULL, provider_event_id VARCHAR(255) NOT NULL, event_type VARCHAR(64) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_event_provider_id ON billing_events (provider_event_id)');
        $this->addSql('CREATE TABLE subscriptions (id UUID NOT NULL, plan VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, provider_customer_id VARCHAR(255) DEFAULT NULL, provider_subscription_id VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4778A01A76ED395 ON subscriptions (user_id)');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_4778A01A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT FK_4778A01A76ED395');
        $this->addSql('DROP TABLE billing_events');
        $this->addSql('DROP TABLE subscriptions');
    }
}
