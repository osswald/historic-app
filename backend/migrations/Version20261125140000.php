<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Macht Abfahrts- und Ankunftszeit einer Fahrt optional, inklusive des zugehörigen Tagversatzes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ride ALTER departure_time DROP NOT NULL');
        $this->addSql('ALTER TABLE ride ALTER departure_offset DROP NOT NULL');
        $this->addSql('ALTER TABLE ride ALTER arrival_time DROP NOT NULL');
        $this->addSql('ALTER TABLE ride ALTER arrival_offset DROP NOT NULL');
        $this->addSql('ALTER TABLE ride ADD CONSTRAINT ride_departure_pair CHECK ((departure_time IS NULL) = (departure_offset IS NULL))');
        $this->addSql('ALTER TABLE ride ADD CONSTRAINT ride_arrival_pair CHECK ((arrival_time IS NULL) = (arrival_offset IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ride DROP CONSTRAINT ride_arrival_pair');
        $this->addSql('ALTER TABLE ride DROP CONSTRAINT ride_departure_pair');
        $this->addSql('DELETE FROM ride WHERE departure_time IS NULL OR arrival_time IS NULL');
        $this->addSql('ALTER TABLE ride ALTER departure_time SET NOT NULL');
        $this->addSql('ALTER TABLE ride ALTER departure_offset SET NOT NULL');
        $this->addSql('ALTER TABLE ride ALTER arrival_time SET NOT NULL');
        $this->addSql('ALTER TABLE ride ALTER arrival_offset SET NOT NULL');
    }
}
