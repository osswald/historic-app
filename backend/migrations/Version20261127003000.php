<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erlaubt, unbenutzte Arten der Fahrt und EVU zu löschen und benutzte inaktiv zu setzen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ride_type ADD active BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE evu ADD active BOOLEAN DEFAULT TRUE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ride_type DROP active');
        $this->addSql('ALTER TABLE evu DROP active');
    }
}
