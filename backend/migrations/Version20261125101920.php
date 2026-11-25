<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125101920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legt die Fahrten einer Veranstaltung an.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ride (id UUID NOT NULL, departure_time TIME(0) WITHOUT TIME ZONE NOT NULL, departure_offset SMALLINT NOT NULL, arrival_time TIME(0) WITHOUT TIME ZONE NOT NULL, arrival_offset SMALLINT NOT NULL, vehicle_label VARCHAR(120) NOT NULL, club_vehicle BOOLEAN NOT NULL, internal_note TEXT DEFAULT NULL, sort_order INT NOT NULL, event_id UUID NOT NULL, event_day_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9B3D7CD071F7E88B ON ride (event_id)');
        $this->addSql('CREATE INDEX IDX_9B3D7CD03B5B3674 ON ride (event_day_id)');
        $this->addSql('ALTER TABLE ride ADD CONSTRAINT FK_9B3D7CD071F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ride ADD CONSTRAINT FK_9B3D7CD03B5B3674 FOREIGN KEY (event_day_id) REFERENCES event_day (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ride DROP CONSTRAINT FK_9B3D7CD071F7E88B');
        $this->addSql('ALTER TABLE ride DROP CONSTRAINT FK_9B3D7CD03B5B3674');
        $this->addSql('DROP TABLE ride');
    }
}
