<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126236000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert die Verknüpfung einer Veranstaltung mit einer bestehenden Helferplanung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_helfer_link (id UUID NOT NULL, event_id UUID NOT NULL, helfer_event_id VARCHAR(80) NOT NULL, title VARCHAR(500) DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX event_helfer_link_event ON event_helfer_link (event_id)');
        $this->addSql('ALTER TABLE event_helfer_link ADD CONSTRAINT FK_EVENT_HELFER_LINK_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_helfer_link DROP CONSTRAINT FK_EVENT_HELFER_LINK_EVENT');
        $this->addSql('DROP TABLE event_helfer_link');
    }
}
