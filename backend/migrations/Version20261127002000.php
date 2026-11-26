<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert den Nextcloud-Ordner einer Veranstaltung über die stabile Datei-Kennung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_nextcloud_folder (id UUID NOT NULL, event_id UUID NOT NULL, file_id VARCHAR(32) NOT NULL, oc_id VARCHAR(190) DEFAULT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_nextcloud_folder_event ON event_nextcloud_folder (event_id)');
        $this->addSql('ALTER TABLE event_nextcloud_folder ADD CONSTRAINT FK_EVENT_NEXTCLOUD_FOLDER_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_nextcloud_folder DROP CONSTRAINT FK_EVENT_NEXTCLOUD_FOLDER_EVENT');
        $this->addSql('DROP TABLE event_nextcloud_folder');
    }
}
