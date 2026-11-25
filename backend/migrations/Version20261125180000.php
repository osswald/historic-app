<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orte und Treffpunkte, Fahrzeugliste am Anlass, externe Fahrzeuge im Stamm, Wartungsfelder, Umfrage-Übernahme je Vorschlag, Gästezahl und Bahnhofskürzel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE place (id UUID NOT NULL, name VARCHAR(200) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_place_name ON place (name)');
        $this->addSql('CREATE TABLE event_meeting_point (id UUID NOT NULL, meets_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL, description TEXT DEFAULT NULL, sort_order INT NOT NULL, event_day_id UUID NOT NULL, place_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_MEETING_DAY ON event_meeting_point (event_day_id)');
        $this->addSql('CREATE INDEX IDX_MEETING_PLACE ON event_meeting_point (place_id)');
        $this->addSql('ALTER TABLE event_meeting_point ADD CONSTRAINT FK_MEETING_DAY FOREIGN KEY (event_day_id) REFERENCES event_day (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_meeting_point ADD CONSTRAINT FK_MEETING_PLACE FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("INSERT INTO place (id, name) SELECT gen_random_uuid(), name FROM (SELECT DISTINCT btrim(location) AS name FROM event_day WHERE location IS NOT NULL AND btrim(location) <> '' UNION SELECT DISTINCT btrim(meeting_point_name) AS name FROM event_day WHERE meeting_point_name IS NOT NULL AND btrim(meeting_point_name) <> '' UNION SELECT DISTINCT btrim(location) AS name FROM event WHERE location IS NOT NULL AND btrim(location) <> '') names WHERE name <> ''");
        $this->addSql('INSERT INTO event_meeting_point (id, meets_at, description, sort_order, event_day_id, place_id) SELECT gen_random_uuid(), event_day.starts_at, event_day.meeting_point_description, 0, event_day.id, place.id FROM event_day JOIN place ON place.name = btrim(event_day.meeting_point_name) WHERE event_day.meeting_point_name IS NOT NULL AND btrim(event_day.meeting_point_name) <> \'\'');
        $this->addSql('ALTER TABLE event_day DROP meeting_point_name');
        $this->addSql('ALTER TABLE event_day DROP meeting_point_description');

        $this->addSql('ALTER TABLE vehicle ADD external BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE vehicle ADD owner VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD nvr VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD p1_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD p5_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD oil_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD next_maintenance_on DATE DEFAULT NULL');

        $this->addSql('CREATE TABLE event_vehicle (id UUID NOT NULL, rental_status VARCHAR(80) DEFAULT NULL, sort_order INT NOT NULL, event_id UUID NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_vehicle ON event_vehicle (event_id, vehicle_id)');
        $this->addSql('CREATE INDEX IDX_EVENT_VEHICLE_VEHICLE ON event_vehicle (vehicle_id)');
        $this->addSql('ALTER TABLE event_vehicle ADD CONSTRAINT FK_EVENT_VEHICLE_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_vehicle ADD CONSTRAINT FK_EVENT_VEHICLE_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('INSERT INTO event_vehicle (id, rental_status, sort_order, event_id, vehicle_id) SELECT gen_random_uuid(), NULL, 0, ride.event_id, ride_vehicle.vehicle_id FROM ride_vehicle JOIN ride ON ride.id = ride_vehicle.ride_id WHERE ride_vehicle.vehicle_id IS NOT NULL GROUP BY ride.event_id, ride_vehicle.vehicle_id');

        $this->addSql('ALTER TABLE ride_vehicle DROP CONSTRAINT ride_vehicle_shape');
        $this->addSql("INSERT INTO vehicle (id, name, number, status, technical_note, tracks_motor_hours, sort_order, created_at, updated_at, external, owner, nvr) SELECT gen_random_uuid(), external_name, NULL, 'ready', NULL, false, 1000, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, true, NULL, NULL FROM (SELECT DISTINCT btrim(external_name) AS external_name FROM ride_vehicle WHERE kind = 'external' AND external_name IS NOT NULL AND btrim(external_name) <> '') names");
        $this->addSql("UPDATE ride_vehicle SET vehicle_id = vehicle.id FROM vehicle WHERE ride_vehicle.kind = 'external' AND vehicle.external = true AND vehicle.name = btrim(ride_vehicle.external_name) AND ride_vehicle.vehicle_id IS NULL");
        $this->addSql('INSERT INTO event_vehicle (id, rental_status, sort_order, event_id, vehicle_id) SELECT gen_random_uuid(), NULL, 0, ride.event_id, ride_vehicle.vehicle_id FROM ride_vehicle JOIN ride ON ride.id = ride_vehicle.ride_id WHERE ride_vehicle.vehicle_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_vehicle existing WHERE existing.event_id = ride.event_id AND existing.vehicle_id = ride_vehicle.vehicle_id) GROUP BY ride.event_id, ride_vehicle.vehicle_id');
        $this->addSql('ALTER TABLE ride_vehicle DROP external_name');
        $this->addSql('ALTER TABLE ride_vehicle ALTER vehicle_id SET NOT NULL');
        $this->addSql('ALTER TABLE ride_vehicle ADD CONSTRAINT ride_vehicle_has_vehicle CHECK (vehicle_id IS NOT NULL)');

        $this->addSql('ALTER TABLE ride ADD origin_abbreviation VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE ride ADD destination_abbreviation VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE poll_option ADD adopted_event_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE gastro_plan ADD guest_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE gastro_plan ADD CONSTRAINT gastro_guest_count_nonneg CHECK (guest_count IS NULL OR guest_count >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gastro_plan DROP CONSTRAINT gastro_guest_count_nonneg');
        $this->addSql('ALTER TABLE gastro_plan DROP guest_count');
        $this->addSql('ALTER TABLE poll_option DROP adopted_event_id');
        $this->addSql('ALTER TABLE ride DROP destination_abbreviation');
        $this->addSql('ALTER TABLE ride DROP origin_abbreviation');
        $this->addSql('ALTER TABLE ride_vehicle DROP CONSTRAINT ride_vehicle_has_vehicle');
        $this->addSql('ALTER TABLE ride_vehicle ADD external_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE ride_vehicle ALTER vehicle_id DROP NOT NULL');
        $this->addSql("ALTER TABLE ride_vehicle ADD CONSTRAINT ride_vehicle_shape CHECK ((kind = 'own' AND vehicle_id IS NOT NULL AND external_name IS NULL) OR (kind = 'external' AND vehicle_id IS NULL AND external_name IS NOT NULL))");
        $this->addSql('ALTER TABLE event_vehicle DROP CONSTRAINT FK_EVENT_VEHICLE_VEHICLE');
        $this->addSql('ALTER TABLE event_vehicle DROP CONSTRAINT FK_EVENT_VEHICLE_EVENT');
        $this->addSql('DROP TABLE event_vehicle');
        $this->addSql('ALTER TABLE vehicle DROP next_maintenance_on');
        $this->addSql('ALTER TABLE vehicle DROP oil_on');
        $this->addSql('ALTER TABLE vehicle DROP p5_on');
        $this->addSql('ALTER TABLE vehicle DROP p1_on');
        $this->addSql('ALTER TABLE vehicle DROP nvr');
        $this->addSql('ALTER TABLE vehicle DROP owner');
        $this->addSql('ALTER TABLE vehicle DROP external');
        $this->addSql('ALTER TABLE event_day ADD meeting_point_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE event_day ADD meeting_point_description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE event_meeting_point DROP CONSTRAINT FK_MEETING_PLACE');
        $this->addSql('ALTER TABLE event_meeting_point DROP CONSTRAINT FK_MEETING_DAY');
        $this->addSql('DROP TABLE event_meeting_point');
        $this->addSql('DROP TABLE place');
    }
}
