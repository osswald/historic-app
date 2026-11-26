<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126082000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spond-Termine können als Anlass im Vereinskalender liegen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD spond_event_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX event_spond_event_id ON event (spond_event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX event_spond_event_id');
        $this->addSql('ALTER TABLE event DROP spond_event_id');
    }
}
