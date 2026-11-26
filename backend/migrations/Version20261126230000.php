<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Angemeldete Konten können eine Spond-Mitglieder-ID tragen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE member_link (sub VARCHAR(190) NOT NULL, spond_id VARCHAR(80) NOT NULL, PRIMARY KEY (sub))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE member_link');
    }
}
