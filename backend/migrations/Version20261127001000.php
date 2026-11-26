<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Fahrten um Via-Bahnhöfe, Art der Fahrt und EVU und speichert die allgemeine Fahrtenplanung am Anlass.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ride_type (id UUID NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ride_type_name_lower ON ride_type (lower(name))');
        $this->addSql('CREATE TABLE evu (id UUID NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_evu_name_lower ON evu (lower(name))');
        $this->addSql('CREATE TABLE ride_via (id UUID NOT NULL, didok_code VARCHAR(12) NOT NULL, name VARCHAR(120) NOT NULL, abbreviation VARCHAR(20) DEFAULT NULL, sort_order INT NOT NULL, ride_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_RIDE_VIA_RIDE ON ride_via (ride_id)');
        $this->addSql('CREATE TABLE event_ride_plan (id UUID NOT NULL, driver_reported BOOLEAN DEFAULT NULL, path_status VARCHAR(255) DEFAULT NULL, hire_status VARCHAR(255) DEFAULT NULL, shunting_note TEXT DEFAULT NULL, breaks_note TEXT DEFAULT NULL, further_info TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_ride_plan_event ON event_ride_plan (event_id)');
        $this->addSql('ALTER TABLE ride ADD ride_type_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ride ADD evu_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ride_via ADD CONSTRAINT FK_RIDE_VIA_RIDE FOREIGN KEY (ride_id) REFERENCES ride (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_ride_plan ADD CONSTRAINT FK_EVENT_RIDE_PLAN_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ride ADD CONSTRAINT FK_RIDE_RIDE_TYPE FOREIGN KEY (ride_type_id) REFERENCES ride_type (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ride ADD CONSTRAINT FK_RIDE_EVU FOREIGN KEY (evu_id) REFERENCES evu (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_RIDE_RIDE_TYPE ON ride (ride_type_id)');
        $this->addSql('CREATE INDEX IDX_RIDE_EVU ON ride (evu_id)');
        $this->addSql("INSERT INTO ride_type (id, name, sort_order) VALUES ('018f0000-0000-7000-8000-000000000001', 'Personenextrazug (NON-PUBLIC)', 0), ('018f0000-0000-7000-8000-000000000002', 'Leermaterialextrazug', 1), ('018f0000-0000-7000-8000-000000000003', 'Lokextrazug', 2)");
        $this->addSql("INSERT INTO evu (id, name, sort_order) VALUES ('018f0000-0000-7000-8000-000000000011', 'SBBP', 0), ('018f0000-0000-7000-8000-000000000012', 'TR', 1), ('018f0000-0000-7000-8000-000000000013', 'RAILH', 2), ('018f0000-0000-7000-8000-000000000014', 'DVZO', 3)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ride DROP CONSTRAINT FK_RIDE_RIDE_TYPE');
        $this->addSql('ALTER TABLE ride DROP CONSTRAINT FK_RIDE_EVU');
        $this->addSql('DROP INDEX IDX_RIDE_RIDE_TYPE');
        $this->addSql('DROP INDEX IDX_RIDE_EVU');
        $this->addSql('ALTER TABLE ride DROP ride_type_id');
        $this->addSql('ALTER TABLE ride DROP evu_id');
        $this->addSql('ALTER TABLE ride_via DROP CONSTRAINT FK_RIDE_VIA_RIDE');
        $this->addSql('ALTER TABLE event_ride_plan DROP CONSTRAINT FK_EVENT_RIDE_PLAN_EVENT');
        $this->addSql('DROP TABLE ride_via');
        $this->addSql('DROP TABLE event_ride_plan');
        $this->addSql('DROP TABLE ride_type');
        $this->addSql('DROP TABLE evu');
    }
}
