<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spond-Leseabfrage: Gruppe, Termin, Personen mit Spond-ID und Antworten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spond_read (id UUID NOT NULL, group_name VARCHAR(200) NOT NULL, group_spond_id VARCHAR(80) NOT NULL, event_heading VARCHAR(300) NOT NULL, event_spond_id VARCHAR(80) NOT NULL, event_on DATE NOT NULL, description TEXT DEFAULT NULL, comment TEXT DEFAULT NULL, options JSON NOT NULL, read_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE spond_person (id UUID NOT NULL, spond_id VARCHAR(80) NOT NULL, matched_sub VARCHAR(190) DEFAULT NULL, read_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX spond_person_read ON spond_person (read_id, spond_id)');
        $this->addSql('CREATE INDEX IDX_SPOND_PERSON_READ ON spond_person (read_id)');
        $this->addSql('CREATE TABLE spond_answer (id UUID NOT NULL, spond_id VARCHAR(80) NOT NULL, reply VARCHAR(255) NOT NULL, comment TEXT DEFAULT NULL, matched_sub VARCHAR(190) DEFAULT NULL, read_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX spond_answer_read ON spond_answer (read_id, spond_id)');
        $this->addSql('CREATE INDEX IDX_SPOND_ANSWER_READ ON spond_answer (read_id)');
        $this->addSql('ALTER TABLE spond_person ADD CONSTRAINT FK_SPOND_PERSON_READ FOREIGN KEY (read_id) REFERENCES spond_read (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE spond_answer ADD CONSTRAINT FK_SPOND_ANSWER_READ FOREIGN KEY (read_id) REFERENCES spond_read (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spond_person DROP CONSTRAINT FK_SPOND_PERSON_READ');
        $this->addSql('ALTER TABLE spond_answer DROP CONSTRAINT FK_SPOND_ANSWER_READ');
        $this->addSql('DROP TABLE spond_answer');
        $this->addSql('DROP TABLE spond_person');
        $this->addSql('DROP TABLE spond_read');
    }
}
