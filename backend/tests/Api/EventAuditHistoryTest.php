<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Domain\Audit\AuditEntry;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EventAuditHistoryTest extends ApiTestCase
{
    public function testEventHistoryIncludesRideAttendanceAndVehicleName(): void
    {
        $client = $this->browser();
        $vehicleId = $this->createVehicle($client, 'Ae 3/6');
        $event = $this->createEvent($client, 'Charterfahrt Test', $vehicleId);
        $other = $this->createEvent($client, 'Andere Fahrt', null);
        $eventId = $event['id'];
        $dayId = $event['days'][0]['id'];
        self::assertIsString($eventId);
        self::assertIsString($dayId);
        self::assertIsString($other['id']);
        self::assertIsString($other['days'][0]['id']);

        $this->request($client, 'PUT', '/api/v1/events/'.$other['id'].'/rides', [
            'rides' => [$this->ride($other['days'][0]['id'], $vehicleId, '77999')],
        ]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', [
            'rides' => [$this->ride($dayId, $vehicleId, '88123')],
        ]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'POST', '/api/v1/event-days/'.$dayId.'/responses', [
            'answer' => 'yes',
            'comment' => 'Ich fahre mit.',
        ]);
        self::assertResponseIsSuccessful();

        $entities = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $entities->persist(new AuditEntry(
            'dev|local',
            'event',
            $eventId,
            'update',
            ['vehicles' => [['vehicleId' => $vehicleId, 'rentalStatus' => 'Nur gelagert']]],
            ['vehicles' => []],
            new \DateTimeImmutable('2020-01-15 09:00:00', new \DateTimeZone('UTC')),
            'Demo Mitglied',
        ));
        $entities->flush();

        $this->request($client, 'GET', '/api/v1/audit?entityType=event&entityId='.$eventId);
        self::assertResponseIsSuccessful();
        $entries = $this->jsonBody($client)['entries'];
        self::assertIsArray($entries);
        self::assertNotSame([], $entries);
        self::assertSame('Teilnahme · Angelegt', $entries[0]['actionLabel']);
        self::assertSame('Demo Mitglied', $entries[0]['actor']);
        self::assertMatchesRegularExpression('/^\d{1,2}\. \S+ \d{4}, \d{2}:\d{2}$/', (string) $entries[0]['occurredAt']);

        $text = $this->changeText($entries);
        self::assertStringContainsString('Fahrt · Angelegt', $text);
        self::assertStringContainsString('88123', $text);
        self::assertStringContainsString('Teilnahme · Angelegt', $text);
        self::assertStringContainsString('Ja', $text);
        self::assertStringContainsString('Ich fahre mit.', $text);
        self::assertStringContainsString('05.12.2026', $text);
        self::assertStringContainsString('Ae 3/6', $text);
        self::assertStringContainsString('Nur gelagert', $text);
        self::assertStringNotContainsString($vehicleId, $text);
        self::assertStringNotContainsString('77999', $text);

        $stored = $this->storedVehicle();
        self::assertSame($vehicleId, $stored['vehicleId'] ?? null);
        self::assertArrayNotHasKey('name', $stored);

        $this->request($client, 'GET', '/api/v1/audit?entityType=event&entityId='.$other['id']);
        self::assertResponseIsSuccessful();
        $otherText = $this->changeText($this->jsonBody($client)['entries']);
        self::assertStringContainsString('77999', $otherText);
        self::assertStringNotContainsString('88123', $otherText);
        self::assertStringNotContainsString('Ich fahre mit.', $otherText);
    }

    /** @param array<string, mixed> $entries */
    private function changeText(array $entries): string
    {
        $parts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parts[] = (string) ($entry['actionLabel'] ?? '');
            $changes = $entry['changes'] ?? [];
            if (!is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $parts[] = (string) ($change['label'] ?? '');
                $parts[] = (string) ($change['before'] ?? '');
                $parts[] = (string) ($change['after'] ?? '');
            }
        }

        return implode("\n", $parts);
    }

    /** @return array<string, mixed> */
    private function storedVehicle(): array
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $raw = $connection->fetchOne(
            'SELECT before_data FROM audit_entry WHERE before_data::text LIKE :needle',
            ['needle' => '%Nur gelagert%'],
        );
        self::assertTrue(is_string($raw) || is_array($raw));
        $decoded = is_array($raw) ? $raw : json_decode($raw, true);
        self::assertIsArray($decoded);
        $vehicle = $decoded['vehicles'][0] ?? null;
        self::assertIsArray($vehicle);

        return $vehicle;
    }

    private function createVehicle(KernelBrowser $client, string $name): string
    {
        $this->request($client, 'POST', '/api/v1/vehicles', [
            'name' => $name,
            'number' => null,
            'status' => 'ready',
            'tracksMotorHours' => true,
            'technicalNote' => null,
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody($client)['id'];
        self::assertIsString($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function createEvent(KernelBrowser $client, string $title, ?string $vehicleId): array
    {
        $payload = [
            'title' => $title,
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
            'status' => 'published',
            'days' => [[
                'date' => '2026-12-05',
                'startsAt' => '09:00',
                'endsAt' => '16:00',
                'location' => 'Winterthur',
            ]],
        ];
        if ($vehicleId !== null) {
            $payload['vehicles'] = [['vehicleId' => $vehicleId, 'rentalStatus' => 'vereinseigen']];
        }
        $this->request($client, 'POST', '/api/v1/events', $payload);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody($client);
    }

    /** @return array<string, mixed> */
    private function ride(string $dayId, string $vehicleId, string $trainNumber): array
    {
        return [
            'eventDayId' => $dayId,
            'departureTime' => '09:10',
            'departureOffset' => 0,
            'arrivalTime' => '17:30',
            'arrivalOffset' => 0,
            'trainNumber' => $trainNumber,
            'origin' => ['didokCode' => '8506000', 'name' => 'Winterthur'],
            'destination' => ['didokCode' => '8503130', 'name' => 'Hinwil'],
            'vehicles' => [[
                'kind' => 'own',
                'vehicleId' => $vehicleId,
            ]],
        ];
    }
}
