<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125091553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE poll (id UUID NOT NULL, title VARCHAR(200) NOT NULL, kind VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, location VARCHAR(200) DEFAULT NULL, clubs JSON NOT NULL, deadline TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_event_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE poll_answer (id UUID NOT NULL, auth0_sub VARCHAR(190) NOT NULL, respondent_name VARCHAR(200) NOT NULL, comment TEXT DEFAULT NULL, saved_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, poll_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX answer_poll_member ON poll_answer (poll_id, auth0_sub)');
        $this->addSql('CREATE INDEX IDX_36D8097E3C947C0F ON poll_answer (poll_id)');
        $this->addSql('CREATE TABLE poll_option (id UUID NOT NULL, occurs_on DATE NOT NULL, daypart VARCHAR(255) DEFAULT NULL, starts_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL, ends_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL, sort_order INT NOT NULL, revision INT NOT NULL, poll_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B68343EB3C947C0F ON poll_option (poll_id)');
        $this->addSql('CREATE TABLE poll_vote (id UUID NOT NULL, auth0_sub VARCHAR(190) NOT NULL, answer VARCHAR(255) NOT NULL, revision INT NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, poll_option_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX vote_option_member ON poll_vote (poll_option_id, auth0_sub)');
        $this->addSql('CREATE INDEX IDX_ED568EBE6C13349B ON poll_vote (poll_option_id)');
        $this->addSql('ALTER TABLE poll_answer ADD CONSTRAINT FK_36D8097E3C947C0F FOREIGN KEY (poll_id) REFERENCES poll (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE poll_option ADD CONSTRAINT FK_B68343EB3C947C0F FOREIGN KEY (poll_id) REFERENCES poll (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE poll_vote ADD CONSTRAINT FK_ED568EBE6C13349B FOREIGN KEY (poll_option_id) REFERENCES poll_option (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE poll_answer DROP CONSTRAINT FK_36D8097E3C947C0F');
        $this->addSql('ALTER TABLE poll_option DROP CONSTRAINT FK_B68343EB3C947C0F');
        $this->addSql('ALTER TABLE poll_vote DROP CONSTRAINT FK_ED568EBE6C13349B');
        $this->addSql('DROP TABLE poll');
        $this->addSql('DROP TABLE poll_answer');
        $this->addSql('DROP TABLE poll_option');
        $this->addSql('DROP TABLE poll_vote');
    }
}
