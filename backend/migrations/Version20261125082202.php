<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125082202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_entry (id UUID NOT NULL, actor_sub VARCHAR(190) NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id VARCHAR(64) NOT NULL, action VARCHAR(40) NOT NULL, before_data JSON DEFAULT NULL, after_data JSON DEFAULT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE event (id UUID NOT NULL, title VARCHAR(200) NOT NULL, clubs JSON NOT NULL, categories JSON NOT NULL, status VARCHAR(255) NOT NULL, internal_description TEXT DEFAULT NULL, public_description TEXT DEFAULT NULL, location VARCHAR(200) DEFAULT NULL, publish_to_website BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE event_day (id UUID NOT NULL, occurs_on DATE NOT NULL, starts_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL, ends_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL, location VARCHAR(200) DEFAULT NULL, meeting_point_name VARCHAR(200) DEFAULT NULL, meeting_point_description TEXT DEFAULT NULL, sort_order INT NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F46FEC4371F7E88B ON event_day (event_id)');
        $this->addSql('CREATE TABLE event_response (id UUID NOT NULL, auth0_sub VARCHAR(190) NOT NULL, respondent_name VARCHAR(200) NOT NULL, answer VARCHAR(255) NOT NULL, comment TEXT DEFAULT NULL, revision INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, event_day_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX response_day_member ON event_response (event_day_id, auth0_sub)');
        $this->addSql('CREATE INDEX IDX_7897E22B3B5B3674 ON event_response (event_day_id)');
        $this->addSql('ALTER TABLE event_day ADD CONSTRAINT FK_F46FEC4371F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_response ADD CONSTRAINT FK_7897E22B3B5B3674 FOREIGN KEY (event_day_id) REFERENCES event_day (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_day DROP CONSTRAINT FK_F46FEC4371F7E88B');
        $this->addSql('ALTER TABLE event_response DROP CONSTRAINT FK_7897E22B3B5B3674');
        $this->addSql('DROP TABLE audit_entry');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE event_day');
        $this->addSql('DROP TABLE event_response');
    }
}
