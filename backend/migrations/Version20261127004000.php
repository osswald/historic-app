<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127004000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert den Namen der Person auf neuen Einträgen im Änderungsverlauf.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_entry ADD actor_name VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_entry DROP actor_name');
    }
}
