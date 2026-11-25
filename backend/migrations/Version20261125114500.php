<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125114500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erweitert Fahrten um Zugnummer, Bahnhöfe und Fahrzeuge und legt den Fahrzeugstamm an.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM ride');
        $this->addSql('ALTER TABLE ride DROP vehicle_label');
        $this->addSql('ALTER TABLE ride DROP club_vehicle');
        $this->addSql('ALTER TABLE ride ADD train_number VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE ride ADD origin_didok_code VARCHAR(12) NOT NULL');
        $this->addSql('ALTER TABLE ride ADD origin_name VARCHAR(120) NOT NULL');
        $this->addSql('ALTER TABLE ride ADD destination_didok_code VARCHAR(12) NOT NULL');
        $this->addSql('ALTER TABLE ride ADD destination_name VARCHAR(120) NOT NULL');
        $this->addSql('CREATE TABLE vehicle (id UUID NOT NULL, name VARCHAR(120) NOT NULL, number VARCHAR(40) DEFAULT NULL, status VARCHAR(255) NOT NULL, technical_note TEXT DEFAULT NULL, tracks_motor_hours BOOLEAN NOT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE battery_charge (id UUID NOT NULL, charged_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, actor_sub VARCHAR(190) NOT NULL, actor_name VARCHAR(200) NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BATTERY_VEHICLE ON battery_charge (vehicle_id)');
        $this->addSql('CREATE TABLE meter_reading (id UUID NOT NULL, kind VARCHAR(255) NOT NULL, reading NUMERIC(12, 1) NOT NULL, read_on DATE NOT NULL, actor_sub VARCHAR(190) NOT NULL, actor_name VARCHAR(200) NOT NULL, recorded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_METER_VEHICLE ON meter_reading (vehicle_id)');
        $this->addSql('CREATE TABLE ride_vehicle (id UUID NOT NULL, kind VARCHAR(255) NOT NULL, external_name VARCHAR(120) DEFAULT NULL, sort_order INT NOT NULL, ride_id UUID NOT NULL, vehicle_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_RIDE_VEHICLE_RIDE ON ride_vehicle (ride_id)');
        $this->addSql('CREATE INDEX IDX_RIDE_VEHICLE_VEHICLE ON ride_vehicle (vehicle_id)');
        $this->addSql('ALTER TABLE battery_charge ADD CONSTRAINT FK_BATTERY_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meter_reading ADD CONSTRAINT FK_METER_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ride_vehicle ADD CONSTRAINT FK_RIDE_VEHICLE_RIDE FOREIGN KEY (ride_id) REFERENCES ride (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ride_vehicle ADD CONSTRAINT FK_RIDE_VEHICLE_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("ALTER TABLE ride_vehicle ADD CONSTRAINT ride_vehicle_shape CHECK ((kind = 'own' AND vehicle_id IS NOT NULL AND external_name IS NULL) OR (kind = 'external' AND vehicle_id IS NULL AND external_name IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ride_vehicle DROP CONSTRAINT ride_vehicle_shape');
        $this->addSql('ALTER TABLE ride_vehicle DROP CONSTRAINT FK_RIDE_VEHICLE_RIDE');
        $this->addSql('ALTER TABLE ride_vehicle DROP CONSTRAINT FK_RIDE_VEHICLE_VEHICLE');
        $this->addSql('ALTER TABLE battery_charge DROP CONSTRAINT FK_BATTERY_VEHICLE');
        $this->addSql('ALTER TABLE meter_reading DROP CONSTRAINT FK_METER_VEHICLE');
        $this->addSql('DROP TABLE ride_vehicle');
        $this->addSql('DROP TABLE meter_reading');
        $this->addSql('DROP TABLE battery_charge');
        $this->addSql('DROP TABLE vehicle');
        $this->addSql('DELETE FROM ride');
        $this->addSql('ALTER TABLE ride DROP train_number');
        $this->addSql('ALTER TABLE ride DROP origin_didok_code');
        $this->addSql('ALTER TABLE ride DROP origin_name');
        $this->addSql('ALTER TABLE ride DROP destination_didok_code');
        $this->addSql('ALTER TABLE ride DROP destination_name');
        $this->addSql('ALTER TABLE ride ADD vehicle_label VARCHAR(120) NOT NULL');
        $this->addSql('ALTER TABLE ride ADD club_vehicle BOOLEAN NOT NULL');
    }
}
