<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126235000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verknüpft Aufgabenlisten mit Veranstaltungen. Dieselbe Liste darf an mehreren Veranstaltungen hängen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_task_list (id UUID NOT NULL, event_id UUID NOT NULL, task_list_id VARCHAR(80) NOT NULL, name VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX event_task_list_event_list ON event_task_list (event_id, task_list_id)');
        $this->addSql('CREATE INDEX IDX_EVENT_TASK_LIST_EVENT ON event_task_list (event_id)');
        $this->addSql('ALTER TABLE event_task_list ADD CONSTRAINT FK_EVENT_TASK_LIST_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_task_list DROP CONSTRAINT FK_EVENT_TASK_LIST_EVENT');
        $this->addSql('DROP TABLE event_task_list');
    }
}
