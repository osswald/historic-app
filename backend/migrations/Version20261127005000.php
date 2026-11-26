<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127005000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legt den Katalog der Veranstaltungskategorien an und übernimmt bestehende Namen von den Anlässen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_category (id UUID NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_category_name_lower ON event_category (lower(name))');
        $this->addSql('ALTER TABLE event ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_CATEGORY FOREIGN KEY (category_id) REFERENCES event_category (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_EVENT_CATEGORY ON event (category_id)');
        $this->addSql(<<<'SQL'
            INSERT INTO event_category (id, name, sort_order, active)
            SELECT gen_random_uuid(), picked.name, picked.sort_order, TRUE
            FROM (
                SELECT name, (ROW_NUMBER() OVER (ORDER BY lower_name)) - 1 AS sort_order
                FROM (
                    SELECT MIN(btrim(value)) AS name, lower(btrim(value)) AS lower_name
                    FROM event
                    CROSS JOIN LATERAL jsonb_array_elements_text(categories::jsonb) AS value
                    WHERE jsonb_typeof(categories::jsonb) = 'array'
                      AND btrim(value) <> ''
                    GROUP BY lower(btrim(value))
                ) AS distinct_names
            ) AS picked
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE event AS target
            SET category_id = category.id
            FROM (
                SELECT event.id AS event_id, linked.name
                FROM event
                CROSS JOIN LATERAL (
                    SELECT btrim(value) AS name
                    FROM jsonb_array_elements_text(event.categories::jsonb) AS value
                    WHERE btrim(value) <> ''
                    LIMIT 1
                ) AS linked
                WHERE jsonb_typeof(event.categories::jsonb) = 'array'
            ) AS chosen
            JOIN event_category AS category ON lower(category.name) = lower(chosen.name)
            WHERE target.id = chosen.event_id
            SQL);
        $this->addSql('ALTER TABLE event DROP categories');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE event ADD categories JSON DEFAULT '[]' NOT NULL");
        $this->addSql("UPDATE event SET categories = COALESCE((SELECT json_build_array(category.name) FROM event_category category WHERE category.id = event.category_id), '[]'::json)");
        $this->addSql('ALTER TABLE event ALTER categories DROP DEFAULT');
        $this->addSql('ALTER TABLE event DROP CONSTRAINT FK_EVENT_CATEGORY');
        $this->addSql('DROP INDEX IDX_EVENT_CATEGORY');
        $this->addSql('ALTER TABLE event DROP category_id');
        $this->addSql('DROP TABLE event_category');
    }
}
