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

        $vehicleId = $this->createVehicle($client, 'Be 4/6');
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, $vehicleId, '09:10', 0, '17:30', 0, 'Nur für die Planung')],
        ]);

        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($client);
        self::assertTrue($saved['canEdit']);
        self::assertSame([], $saved['warnings']);
        self::assertSame('09:10', $saved['rides'][0]['departureTime']);
        self::assertSame(0, $saved['rides'][0]['departureOffset']);
        self::assertSame('17:30', $saved['rides'][0]['arrivalTime']);
        self::assertSame(0, $saved['rides'][0]['arrivalOffset']);
        self::assertSame('30431', $saved['rides'][0]['trainNumber']);
        self::assertSame('8506000', $saved['rides'][0]['origin']['didokCode']);
        self::assertSame('Winterthur', $saved['rides'][0]['origin']['name']);
        self::assertSame('8503130', $saved['rides'][0]['destination']['didokCode']);
        self::assertSame('Hinwil', $saved['rides'][0]['destination']['name']);
        self::assertSame('own', $saved['rides'][0]['vehicles'][0]['kind']);
        self::assertSame($vehicleId, $saved['rides'][0]['vehicles'][0]['vehicleId']);
        self::assertSame('Be 4/6', $saved['rides'][0]['vehicles'][0]['label']);
        self::assertSame('Nur für die Planung', $saved['rides'][0]['internalNote']);
        $rideId = $saved['rides'][0]['id'];
        self::assertIsString($rideId);

        $updated = $this->ride($dayId, $vehicleId, '09:10', 0, '17:30', 0, 'Nur für die Planung');
        $updated['id'] = $rideId;
        $updated['trainNumber'] = '30432';
        $updated['vehicles'][] = ['kind' => 'external', 'externalName' => 'Mietlok'];
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$updated]]);

        self::assertResponseIsSuccessful();
        self::assertSame($rideId, $this->jsonBody($client)['rides'][0]['id']);
        self::assertSame('30432', $this->jsonBody($client)['rides'][0]['trainNumber']);
        self::assertSame('Mietlok', $this->jsonBody($client)['rides'][0]['vehicles'][1]['label']);
        self::assertSame(['create', 'update'], $this->rideAuditActions());
    }

    public function testArrivalThatIsNotAfterDepartureIsRejected(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Nachtschicht');
        $dayId = $created['days'][0]['id'];

        $vehicleId = $this->createVehicle($client);
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, $vehicleId, '22:00', 0, '06:00', 1)],
        ]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, $vehicleId, '10:00', 0, '18:00', -1)],
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
        $vehicleId = $this->createVehicle($client, 'Be 4/6');
        $draft = $this->createEvent($client, 'Entwurf Fahrt', 'draft');
        $published = $this->createEvent($client, 'Publizierte Fahrt', 'published');

        $this->request($client, 'PUT', '/api/v1/events/'.$draft['id'].'/rides', [
            'rides' => [$this->ride($draft['days'][0]['id'], $vehicleId, '09:00', 0, '12:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$published['id'].'/rides', [
            'rides' => [
                $this->ride($published['days'][0]['id'], $vehicleId, '11:00', 0, '15:00', 0),
                $this->ride($published['days'][0]['id'], $vehicleId, '14:00', 0, '18:00', 0),
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
        $vehicleId = $this->createVehicle($client, 'Be 4/6');
        $created = $this->createEvent($client, 'Sichtbare Fahrt');
        $dayId = $created['days'][0]['id'];
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$this->ride($dayId, $vehicleId, '09:10', 0, '17:30', 0, 'Trasse nur intern')],
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
                    'trainNumber' => '99999',
                    'origin' => ['didokCode' => '8506000', 'name' => 'Winterthur'],
                    'destination' => ['didokCode' => '8503130', 'name' => 'Hinwil'],
                    'vehicles' => [['kind' => 'external', 'externalName' => 'Fremd']],
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
        self::assertSame('30431', $this->jsonBody($organizer)['rides'][0]['trainNumber']);
        self::assertSame($vehicleId, $this->jsonBody($organizer)['rides'][0]['vehicles'][0]['vehicleId']);
    }

    public function testTrainNumberAndStationAreRequired(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Ohne Zugnummer');
        $vehicleId = $this->createVehicle($client);
        $ride = $this->ride($created['days'][0]['id'], $vehicleId, '09:10', 0, '17:30', 0);
        $ride['trainNumber'] = '';
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$ride]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte eine Zugnummer angeben.', $this->jsonBody($client)['errors']['rides.0.trainNumber']);

        $ride['trainNumber'] = '30431';
        $ride['origin'] = null;
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$ride]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte den Startbahnhof aus der Suche wählen.', $this->jsonBody($client)['errors']['rides.0.origin']);
    }

    public function testExternalVehicleDoesNotWarn(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Externe Lok');
        $dayId = $created['days'][0]['id'];
        $external = $this->ride($dayId, 'unused', '09:00', 0, '12:00', 0);
        $external['vehicles'] = [['kind' => 'external', 'externalName' => 'Mietlok']];
        $second = $external;
        $second['departureTime'] = '11:00';
        $second['arrivalTime'] = '15:00';
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$external, $second]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);
        self::assertSame('external', $this->jsonBody($client)['rides'][0]['vehicles'][0]['kind']);
    }

    public function testARideCanBeSavedWithoutDepartureOrArrival(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Zeiten offen');
        $dayId = $created['days'][0]['id'];
        $vehicleId = $this->createVehicle($client);
        $eventId = $created['id'];

        $open = $this->ride($dayId, $vehicleId, null, null, null, null);
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$open]]);
        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($client);
        self::assertNull($saved['rides'][0]['departureTime']);
        self::assertNull($saved['rides'][0]['departureOffset']);
        self::assertNull($saved['rides'][0]['arrivalTime']);
        self::assertNull($saved['rides'][0]['arrivalOffset']);
        self::assertSame('30431', $saved['rides'][0]['trainNumber']);
        self::assertSame('Winterthur', $saved['rides'][0]['origin']['name']);
        self::assertSame($vehicleId, $saved['rides'][0]['vehicles'][0]['vehicleId']);

        $rideId = $saved['rides'][0]['id'];
        $departureOnly = $open;
        $departureOnly['id'] = $rideId;
        $departureOnly['departureTime'] = '09:10';
        $departureOnly['departureOffset'] = -1;
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$departureOnly]]);
        self::assertResponseIsSuccessful();
        self::assertSame('09:10', $this->jsonBody($client)['rides'][0]['departureTime']);
        self::assertSame(-1, $this->jsonBody($client)['rides'][0]['departureOffset']);
        self::assertNull($this->jsonBody($client)['rides'][0]['arrivalTime']);
        self::assertNull($this->jsonBody($client)['rides'][0]['arrivalOffset']);

        $arrivalOnly = $departureOnly;
        $arrivalOnly['departureTime'] = null;
        $arrivalOnly['departureOffset'] = 1;
        $arrivalOnly['arrivalTime'] = '06:30';
        $arrivalOnly['arrivalOffset'] = 1;
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$arrivalOnly]]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody($client)['rides'][0]['departureTime']);
        self::assertNull($this->jsonBody($client)['rides'][0]['departureOffset']);
        self::assertSame('06:30', $this->jsonBody($client)['rides'][0]['arrivalTime']);
        self::assertSame(1, $this->jsonBody($client)['rides'][0]['arrivalOffset']);

        $missingOffset = $open;
        $missingOffset['departureTime'] = '08:00';
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$missingOffset]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Der Tagversatz der Abfahrt ist −1, 0 oder +1.',
            $this->jsonBody($client)['errors']['rides.0.departureOffset'],
        );

        $invalid = $open;
        $invalid['arrivalTime'] = '25:99';
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$invalid]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Bitte eine gültige Ankunftszeit angeben.',
            $this->jsonBody($client)['errors']['rides.0.arrivalTime'],
        );

        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', [
            'rides' => [$this->ride($dayId, $vehicleId, '10:00', 0, '10:00', 0)],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Die Ankunft muss nach der Abfahrt liegen.',
            $this->jsonBody($client)['errors']['rides.0.arrivalTime'],
        );

        $withoutVehicle = $open;
        $withoutVehicle['vehicles'] = [];
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$withoutVehicle]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Bitte mindestens ein Fahrzeug angeben.',
            $this->jsonBody($client)['errors']['rides.0.vehicles'],
        );

        $withoutTrain = $open;
        $withoutTrain['trainNumber'] = '';
        $this->request($client, 'PUT', '/api/v1/events/'.$eventId.'/rides', ['rides' => [$withoutTrain]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte eine Zugnummer angeben.', $this->jsonBody($client)['errors']['rides.0.trainNumber']);

        $this->request($client, 'GET', '/api/v1/events/'.$eventId.'/rides');
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody($client)['rides'][0]['departureTime']);
        self::assertSame('06:30', $this->jsonBody($client)['rides'][0]['arrivalTime']);
    }

    public function testMissingTimesOccupyTheEventDayAndASingleTimeIsThatInstant(): void
    {
        $client = $this->browser();
        $vehicleId = $this->createVehicle($client, 'Be 4/6');
        $openEvent = $this->createEvent($client, 'Offene Fahrt');
        $other = $this->createEvent($client, 'Andere Fahrt');
        $openDay = $openEvent['days'][0]['id'];
        $otherDay = $other['days'][0]['id'];

        $this->request($client, 'PUT', '/api/v1/events/'.$openEvent['id'].'/rides', [
            'rides' => [$this->ride($openDay, $vehicleId, null, null, null, null)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$other['id'].'/rides', [
            'rides' => [$this->ride($otherDay, $vehicleId, '18:00', -1, '20:00', -1)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$other['id'].'/rides', [
            'rides' => [$this->ride($otherDay, $vehicleId, '23:00', -1, '01:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->warningsMention($this->jsonBody($client)['warnings'], 'Offene Fahrt'));

        $this->request($client, 'PUT', '/api/v1/events/'.$other['id'].'/rides', [
            'rides' => [$this->ride($otherDay, $vehicleId, '09:00', 0, '12:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonBody($client)['rides']);

        $this->request($client, 'PUT', '/api/v1/events/'.$openEvent['id'].'/rides', [
            'rides' => [
                $this->ride($openDay, $vehicleId, null, null, null, null),
                $this->ride($openDay, $vehicleId, null, null, null, null),
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->jsonBody($client)['rides']);
        self::assertTrue($this->warningsMention($this->jsonBody($client)['warnings'], 'doppelt eingeteilt'));

        $this->request($client, 'PUT', '/api/v1/events/'.$openEvent['id'].'/rides', [
            'rides' => [$this->ride($openDay, $vehicleId, '11:00', 0, null, null)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->warningsMention($this->jsonBody($client)['warnings'], 'Andere Fahrt'));

        $this->request($client, 'PUT', '/api/v1/events/'.$openEvent['id'].'/rides', [
            'rides' => [$this->ride($openDay, $vehicleId, null, null, '12:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$openEvent['id'].'/rides', [
            'rides' => [$this->ride($openDay, $vehicleId, '09:00', 0, null, null)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->warningsMention($this->jsonBody($client)['warnings'], 'Andere Fahrt'));
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

    private function createVehicle(KernelBrowser $client, string $name = 'Be 4/6'): string
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
    private function ride(
        string $dayId,
        string $vehicleId,
        ?string $departure,
        ?int $departureOffset,
        ?string $arrival,
        ?int $arrivalOffset,
        ?string $note = null,
    ): array {
        return [
            'eventDayId' => $dayId,
            'departureTime' => $departure,
            'departureOffset' => $departureOffset,
            'arrivalTime' => $arrival,
            'arrivalOffset' => $arrivalOffset,
            'trainNumber' => '30431',
            'origin' => ['didokCode' => '8506000', 'name' => 'Winterthur'],
            'destination' => ['didokCode' => '8503130', 'name' => 'Hinwil'],
            'vehicles' => [[
                'kind' => 'own',
                'vehicleId' => $vehicleId,
            ]],
            'internalNote' => $note,
        ];
    }
}
