<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class RidePlanTest extends ApiTestCase
{
    public function testOrganizerCanSaveARide(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Fahrt nach Zürich');
        $dayId = $created['days'][0]['id'];

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, '09:10', 0, '17:30', 0, 'Nur für die Planung')],
        ]);

        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($client);
        self::assertTrue($saved['canEdit']);
        self::assertSame([], $saved['warnings']);
        self::assertSame('09:10', $saved['rides'][0]['departureTime']);
        self::assertSame(0, $saved['rides'][0]['departureOffset']);
        self::assertSame('17:30', $saved['rides'][0]['arrivalTime']);
        self::assertSame(0, $saved['rides'][0]['arrivalOffset']);
        self::assertSame('Vereinsfahrzeug', $saved['rides'][0]['vehicleLabel']);
        self::assertTrue($saved['rides'][0]['clubVehicle']);
        self::assertSame('Nur für die Planung', $saved['rides'][0]['internalNote']);
        $rideId = $saved['rides'][0]['id'];
        self::assertIsString($rideId);

        $updated = $this->ride($dayId, '09:10', 0, '17:30', 0, 'Nur für die Planung');
        $updated['id'] = $rideId;
        $updated['vehicleLabel'] = 'Ae 6/6';
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$updated]]);

        self::assertResponseIsSuccessful();
        self::assertSame($rideId, $this->jsonBody($client)['rides'][0]['id']);
        self::assertSame('Ae 6/6', $this->jsonBody($client)['rides'][0]['vehicleLabel']);
        self::assertSame(['create', 'update'], $this->rideAuditActions());
    }

    public function testArrivalThatIsNotAfterDepartureIsRejected(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Nachtschicht');
        $dayId = $created['days'][0]['id'];

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, '22:00', 0, '06:00', 1)],
        ]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, '10:00', 0, '18:00', -1)],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Die Ankunft muss nach der Abfahrt liegen.',
            $this->jsonBody($client)['errors']['rides.0.arrivalTime'],
        );

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        self::assertSame('22:00', $this->jsonBody($client)['rides'][0]['departureTime']);
        self::assertSame(1, $this->jsonBody($client)['rides'][0]['arrivalOffset']);
    }

    public function testOwnVehicleOverlapWarnsAndStillSaves(): void
    {
        $client = $this->browser();
        $draft = $this->createEvent($client, 'Entwurf Fahrt', 'draft');
        $published = $this->createEvent($client, 'Publizierte Fahrt', 'published');

        $this->request($client, 'PUT', '/api/v1/events/'.$draft['id'].'/rides', [
            'rides' => [$this->ride($draft['days'][0]['id'], '09:00', 0, '12:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$published['id'].'/rides', [
            'rides' => [
                $this->ride($published['days'][0]['id'], '11:00', 0, '15:00', 0),
                $this->ride($published['days'][0]['id'], '14:00', 0, '18:00', 0),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($client);
        self::assertCount(2, $saved['rides']);
        self::assertNotEmpty($saved['warnings']);
        self::assertTrue($this->warningsMention($saved['warnings'], 'Entwurf Fahrt'));
        self::assertTrue($this->warningsMention($saved['warnings'], 'doppelt eingeteilt'));

        $this->request($client, 'GET', '/api/v1/events/'.$published['id'].'/rides');
        self::assertCount(2, $this->jsonBody($client)['rides']);
        $this->request($client, 'GET', '/api/v1/events/'.$draft['id'].'/rides');
        self::assertCount(1, $this->jsonBody($client)['rides']);
        self::assertSame('09:00', $this->jsonBody($client)['rides'][0]['departureTime']);
    }

    public function testMemberCannotUpdateRidesOrSeeInternalNotes(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Sichtbare Fahrt');
        $dayId = $created['days'][0]['id'];
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, '09:10', 0, '17:30', 0, 'Trasse nur intern')],
        ]);
        self::assertResponseIsSuccessful();
        $rideId = $this->jsonBody($client)['rides'][0]['id'];

        $this->withGroups('Mitglieder', function (KernelBrowser $member) use ($created, $dayId, $rideId): void {
            $this->request($member, 'GET', '/api/v1/events/'.$created['id'].'/rides');
            self::assertResponseIsSuccessful();
            $body = $this->jsonBody($member);
            self::assertFalse($body['canEdit']);
            self::assertSame([], $body['warnings']);
            self::assertSame('09:10', $body['rides'][0]['departureTime']);
            self::assertSame('17:30', $body['rides'][0]['arrivalTime']);
            self::assertNull($body['rides'][0]['internalNote']);

            $this->request($member, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
                'rides' => [[
                    'id' => $rideId,
                    'eventDayId' => $dayId,
                    'departureTime' => '10:00',
                    'departureOffset' => 0,
                    'arrivalTime' => '18:00',
                    'arrivalOffset' => 0,
                    'vehicleLabel' => 'Fremd',
                    'clubVehicle' => false,
                    'internalNote' => 'Sollte nicht gespeichert werden',
                ]],
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(
                'Fahrten bearbeiten dürfen Organisatoren und die Fahrtenplanung.',
                $this->jsonBody($member)['error'],
            );
        });

        $organizer = $this->browser();
        $this->request($organizer, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        self::assertSame('Trasse nur intern', $this->jsonBody($organizer)['rides'][0]['internalNote']);
        self::assertSame('09:10', $this->jsonBody($organizer)['rides'][0]['departureTime']);
        self::assertSame('Vereinsfahrzeug', $this->jsonBody($organizer)['rides'][0]['vehicleLabel']);
    }

    /** @param callable(KernelBrowser): void $callback */
    private function withGroups(string $groups, callable $callback): void
    {
        $env = $_ENV['DEV_USER_GROUPS'] ?? 'admin,Mitglieder';
        $server = $_SERVER['DEV_USER_GROUPS'] ?? $env;
        $exported = getenv('DEV_USER_GROUPS');
        $_ENV['DEV_USER_GROUPS'] = $groups;
        $_SERVER['DEV_USER_GROUPS'] = $groups;
        putenv('DEV_USER_GROUPS='.$groups);

        try {
            self::ensureKernelShutdown();
            $callback($this->browser());
        } finally {
            $_ENV['DEV_USER_GROUPS'] = $env;
            $_SERVER['DEV_USER_GROUPS'] = $server;
            if ($exported === false) {
                putenv('DEV_USER_GROUPS');
            } else {
                putenv('DEV_USER_GROUPS='.$exported);
            }
            self::ensureKernelShutdown();
        }
    }

    /** @return list<string> */
    private function rideAuditActions(): array
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $actions = $connection->fetchFirstColumn("SELECT action FROM audit_entry WHERE entity_type = 'ride' ORDER BY occurred_at ASC, action ASC");
        self::assertIsArray($actions);

        return array_map(static fn (mixed $action): string => (string) $action, $actions);
    }

    /**
     * @param list<string> $warnings
     */
    private function warningsMention(array $warnings, string $needle): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function createEvent(KernelBrowser $client, string $title, string $status = 'published'): array
    {
        $this->request($client, 'POST', '/api/v1/events', [
            'title' => $title,
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
            'status' => $status,
            'days' => [[
                'date' => '2026-12-05',
                'startsAt' => '09:00',
                'endsAt' => '16:00',
                'location' => 'Winterthur',
            ]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody($client);
    }

    /** @return array<string, mixed> */
    private function ride(
        string $dayId,
        string $departure,
        int $departureOffset,
        string $arrival,
        int $arrivalOffset,
        ?string $note = null,
    ): array {
        return [
            'eventDayId' => $dayId,
            'departureTime' => $departure,
            'departureOffset' => $departureOffset,
            'arrivalTime' => $arrival,
            'arrivalOffset' => $arrivalOffset,
            'vehicleLabel' => 'Vereinsfahrzeug',
            'clubVehicle' => true,
            'internalNote' => $note,
        ];
    }
}
