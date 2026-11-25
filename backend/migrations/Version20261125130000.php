<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legt eine Gastronomieplanung je Veranstaltung an, mit Gerichten, Portionen, Notiz und Verantwortlichen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gastro_plan (id UUID NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE gastro_plan ADD CONSTRAINT uniq_gastro_plan_event UNIQUE (event_id)');
        $this->addSql('CREATE TABLE gastro_dish (id UUID NOT NULL, name VARCHAR(120) NOT NULL, portions INT NOT NULL, sort_order INT NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_GASTRO_DISH_PLAN ON gastro_dish (plan_id)');
        $this->addSql('CREATE TABLE gastro_responsible (id UUID NOT NULL, member_sub VARCHAR(190) NOT NULL, display_name VARCHAR(200) NOT NULL, sort_order INT NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_GASTRO_RESPONSIBLE_PLAN ON gastro_responsible (plan_id)');
        $this->addSql('ALTER TABLE gastro_plan ADD CONSTRAINT FK_GASTRO_PLAN_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE gastro_dish ADD CONSTRAINT FK_GASTRO_DISH_PLAN FOREIGN KEY (plan_id) REFERENCES gastro_plan (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE gastro_responsible ADD CONSTRAINT FK_GASTRO_RESPONSIBLE_PLAN FOREIGN KEY (plan_id) REFERENCES gastro_plan (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gastro_responsible DROP CONSTRAINT FK_GASTRO_RESPONSIBLE_PLAN');
        $this->addSql('ALTER TABLE gastro_dish DROP CONSTRAINT FK_GASTRO_DISH_PLAN');
        $this->addSql('ALTER TABLE gastro_plan DROP CONSTRAINT uniq_gastro_plan_event');
        $this->addSql('ALTER TABLE gastro_plan DROP CONSTRAINT FK_GASTRO_PLAN_EVENT');
        $this->addSql('DROP TABLE gastro_responsible');
        $this->addSql('DROP TABLE gastro_dish');
        $this->addSql('DROP TABLE gastro_plan');
    }
}
