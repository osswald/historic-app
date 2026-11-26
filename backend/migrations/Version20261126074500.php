<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126074500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Einladungen an der Veranstaltung: Gruppen, namentliche Personen und Ausschlüsse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE event ADD invited_groups JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE event ADD invited_people JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE event ADD excluded_people JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP excluded_people');
        $this->addSql('ALTER TABLE event DROP invited_people');
        $this->addSql('ALTER TABLE event DROP invited_groups');
    }
}
