<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spond-Leseabfrage speichert alle Termine der Gruppe statt eines einzelnen Termins.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spond_event (id UUID NOT NULL, spond_id VARCHAR(80) NOT NULL, heading VARCHAR(300) NOT NULL, event_on DATE NOT NULL, description TEXT DEFAULT NULL, comment TEXT DEFAULT NULL, options JSON NOT NULL, read_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX spond_event_read ON spond_event (read_id, spond_id)');
        $this->addSql('CREATE INDEX IDX_SPOND_EVENT_READ ON spond_event (read_id)');
        $this->addSql('ALTER TABLE spond_event ADD CONSTRAINT FK_SPOND_EVENT_READ FOREIGN KEY (read_id) REFERENCES spond_read (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('INSERT INTO spond_event (id, read_id, spond_id, heading, event_on, description, comment, options) SELECT id, id, event_spond_id, event_heading, event_on, description, comment, options FROM spond_read');
        $this->addSql('ALTER TABLE spond_answer ADD event_id UUID DEFAULT NULL');
        $this->addSql('UPDATE spond_answer SET event_id = read_id');
        $this->addSql('ALTER TABLE spond_answer DROP CONSTRAINT FK_SPOND_ANSWER_READ');
        $this->addSql('DROP INDEX spond_answer_read');
        $this->addSql('DROP INDEX IDX_SPOND_ANSWER_READ');
        $this->addSql('ALTER TABLE spond_answer DROP COLUMN read_id');
        $this->addSql('ALTER TABLE spond_answer ALTER event_id SET NOT NULL');
        $this->addSql('ALTER TABLE spond_answer ADD CONSTRAINT FK_SPOND_ANSWER_EVENT FOREIGN KEY (event_id) REFERENCES spond_event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX spond_answer_event ON spond_answer (event_id, spond_id)');
        $this->addSql('CREATE INDEX IDX_SPOND_ANSWER_EVENT ON spond_answer (event_id)');
        $this->addSql('ALTER TABLE spond_read DROP COLUMN event_heading');
        $this->addSql('ALTER TABLE spond_read DROP COLUMN event_spond_id');
        $this->addSql('ALTER TABLE spond_read DROP COLUMN event_on');
        $this->addSql('ALTER TABLE spond_read DROP COLUMN description');
        $this->addSql('ALTER TABLE spond_read DROP COLUMN comment');
        $this->addSql('ALTER TABLE spond_read DROP COLUMN options');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE spond_read ADD event_heading VARCHAR(300) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE spond_read ADD event_spond_id VARCHAR(80) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE spond_read ADD event_on DATE DEFAULT '1970-01-01' NOT NULL");
        $this->addSql('ALTER TABLE spond_read ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE spond_read ADD comment TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE spond_read ADD options JSON DEFAULT '[]' NOT NULL");
        $this->addSql('UPDATE spond_read SET event_heading = kept.heading, event_spond_id = kept.spond_id, event_on = kept.event_on, description = kept.description, comment = kept.comment, options = kept.options FROM (SELECT DISTINCT ON (read_id) read_id, spond_id, heading, event_on, description, comment, options FROM spond_event ORDER BY read_id, event_on, spond_id) kept WHERE kept.read_id = spond_read.id');
        $this->addSql('ALTER TABLE spond_read ALTER event_heading DROP DEFAULT');
        $this->addSql('ALTER TABLE spond_read ALTER event_spond_id DROP DEFAULT');
        $this->addSql('ALTER TABLE spond_read ALTER event_on DROP DEFAULT');
        $this->addSql('ALTER TABLE spond_read ALTER options DROP DEFAULT');
        $this->addSql('DELETE FROM spond_answer WHERE event_id NOT IN (SELECT DISTINCT ON (read_id) id FROM spond_event ORDER BY read_id, event_on, spond_id)');
        $this->addSql('ALTER TABLE spond_answer ADD read_id UUID DEFAULT NULL');
        $this->addSql('UPDATE spond_answer SET read_id = spond_event.read_id FROM spond_event WHERE spond_event.id = spond_answer.event_id');
        $this->addSql('ALTER TABLE spond_answer ALTER read_id SET NOT NULL');
        $this->addSql('ALTER TABLE spond_answer DROP CONSTRAINT FK_SPOND_ANSWER_EVENT');
        $this->addSql('DROP INDEX spond_answer_event');
        $this->addSql('DROP INDEX IDX_SPOND_ANSWER_EVENT');
        $this->addSql('ALTER TABLE spond_answer DROP COLUMN event_id');
        $this->addSql('CREATE UNIQUE INDEX spond_answer_read ON spond_answer (read_id, spond_id)');
        $this->addSql('CREATE INDEX IDX_SPOND_ANSWER_READ ON spond_answer (read_id)');
        $this->addSql('ALTER TABLE spond_answer ADD CONSTRAINT FK_SPOND_ANSWER_READ FOREIGN KEY (read_id) REFERENCES spond_read (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE spond_event DROP CONSTRAINT FK_SPOND_EVENT_READ');
        $this->addSql('DROP TABLE spond_event');
    }
}
