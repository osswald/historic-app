<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spond-Teilnahmen liegen als Ja oder Nein an der Veranstaltung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_response ALTER auth0_sub DROP NOT NULL');
        $this->addSql('ALTER TABLE event_response ADD spond_member_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE event_response ADD edited_in_app BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE event_response ALTER edited_in_app DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX response_day_spond ON event_response (event_day_id, spond_member_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX response_day_spond');
        $this->addSql('ALTER TABLE event_response DROP spond_member_id');
        $this->addSql('ALTER TABLE event_response DROP edited_in_app');
        $this->addSql('ALTER TABLE event_response ALTER auth0_sub SET NOT NULL');
    }
}
