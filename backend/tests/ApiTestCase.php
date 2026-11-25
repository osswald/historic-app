<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected function setUp(): void
    {
        $_SERVER['AUTH_DEV_MODE'] = $_ENV['AUTH_DEV_MODE'] = '1';
        putenv('AUTH_DEV_MODE=1');
        parent::setUp();
        self::bootKernel();
        $this->emptyTables();
        self::ensureKernelShutdown();
    }

    protected function browser(): KernelBrowser
    {
        return static::createClient([], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    /** @param array<string, mixed>|null $body */
    protected function request(KernelBrowser $client, string $method, string $uri, ?array $body = null, ?string $token = 'dev'): void
    {
        $server = [];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $client->request(
            $method,
            $uri,
            [],
            [],
            $server,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    protected function jsonBody(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function emptyTables(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        $database = (string) $connection->getDatabase();
        if ($database === 'app' || !str_ends_with($database, '_test')) {
            self::fail(sprintf('Refusing to touch database "%s".', $database));
        }

        $schema = $connection->createSchemaManager();
        $present = [];
        foreach (['gastro_dish', 'gastro_responsible', 'gastro_plan', 'ride_vehicle', 'event_vehicle', 'meter_reading', 'battery_charge', 'ride', 'vehicle', 'poll_vote', 'poll_answer', 'poll_option', 'poll', 'event_response', 'event_meeting_point', 'event_day', 'place', 'event', 'audit_entry'] as $table) {
            if ($schema->tablesExist([$table])) {
                $present[] = '"'.$table.'"';
            }
        }
        if ($present === []) {
            return;
        }

        $connection->executeStatement('TRUNCATE '.implode(', ', $present).' RESTART IDENTITY CASCADE');
    }
}
