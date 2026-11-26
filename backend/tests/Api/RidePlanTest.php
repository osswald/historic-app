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
        $externalId = $this->createExternalVehicle($client, 'Mietlok');
        $updated['vehicles'][] = ['vehicleId' => $externalId];
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
        self::assertTrue($this->warningsMention($saved['warnings'], '(Entwurf)'));
        self::assertFalse($this->warningsMention($saved['warnings'], 'doppelt eingeteilt'));

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
                'Fahrten bearbeiten dürfen Administratoren und die Fahrtenplanung.',
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
        $externalId = $this->createExternalVehicle($client, 'Mietlok');
        $external = $this->ride($dayId, $externalId, '09:00', 0, '12:00', 0);
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

    public function testOverlapUsesTheWholeEventPeriodAndSkipsCancelled(): void
    {
        $client = $this->browser();
        $vehicleId = $this->createVehicle($client, 'Be 4/6');
        $draft = $this->createEvent($client, 'Entwurf am selben Tag', 'draft');
        $published = $this->createEvent($client, 'Veröffentlichte Fahrt', 'published');
        $later = $this->createEvent($client, 'Anderer Tag', 'published', '2026-12-20');
        $cancelled = $this->createEvent($client, 'Abgesagte Fahrt', 'cancelled');

        $this->request($client, 'PUT', '/api/v1/events/'.$draft['id'].'/rides', [
            'rides' => [$this->ride($draft['days'][0]['id'], $vehicleId, '08:00', 0, '09:00', 0)],
        ]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'PUT', '/api/v1/events/'.$published['id'].'/rides', [
            'rides' => [$this->ride($published['days'][0]['id'], $vehicleId, '18:00', 0, '19:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        $warnings = $this->jsonBody($client)['warnings'];
        self::assertTrue($this->warningsMention($warnings, 'Entwurf am selben Tag'));
        self::assertTrue($this->warningsMention($warnings, '(Entwurf)'));

        $this->request($client, 'PUT', '/api/v1/events/'.$later['id'].'/rides', [
            'rides' => [$this->ride($later['days'][0]['id'], $vehicleId, '09:00', 0, '12:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$cancelled['id'].'/rides', [
            'rides' => [$this->ride($cancelled['days'][0]['id'], $vehicleId, '08:00', 0, '19:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'PUT', '/api/v1/events/'.$published['id'].'/rides', [
            'rides' => [$this->ride($published['days'][0]['id'], $vehicleId, '18:00', 0, '19:00', 0)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->warningsMention($this->jsonBody($client)['warnings'], 'Abgesagte Fahrt'));
        self::assertTrue($this->warningsMention($this->jsonBody($client)['warnings'], '(Entwurf)'));

        $this->request($client, 'PUT', '/api/v1/events/'.$published['id'].'/rides', [
            'rides' => [
                $this->ride($published['days'][0]['id'], $vehicleId, '09:00', 0, '10:00', 0),
                $this->ride($published['days'][0]['id'], $vehicleId, '15:00', 0, '16:00', 0),
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->warningsMention($this->jsonBody($client)['warnings'], 'doppelt eingeteilt'));
        self::assertTrue($this->warningsMention($this->jsonBody($client)['warnings'], '(Entwurf)'));
    }

    public function testViaStationsRideTypeAndEvuPersistInOrder(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Mit Via');
        $dayId = $created['days'][0]['id'];
        $vehicleId = $this->createVehicle($client, 'Be 4/6');

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        $catalog = $this->jsonBody($client);
        $typeId = $this->catalogId($catalog['rideTypes'], 'Personenextrazug (NON-PUBLIC)');
        $evuId = $this->catalogId($catalog['evus'], 'SBBP');
        self::assertNotNull($this->catalogId($catalog['rideTypes'], 'Leermaterialextrazug'));
        self::assertNotNull($this->catalogId($catalog['rideTypes'], 'Lokextrazug'));
        self::assertNotNull($this->catalogId($catalog['evus'], 'TR'));
        self::assertNotNull($this->catalogId($catalog['evus'], 'RAILH'));
        self::assertNotNull($this->catalogId($catalog['evus'], 'DVZO'));

        $ride = $this->ride($dayId, $vehicleId, null, null, null, null);
        $ride['vias'] = [
            ['didokCode' => '8503123', 'name' => 'Bauma', 'abbreviation' => 'BAU'],
            ['didokCode' => '8503125', 'name' => 'Wald', 'abbreviation' => 'WAL'],
        ];
        $ride['rideTypeId'] = $typeId;
        $ride['evuId'] = $evuId;
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$ride]]);
        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($client);
        self::assertNull($saved['rides'][0]['departureTime']);
        self::assertNull($saved['rides'][0]['arrivalTime']);
        self::assertSame('Bauma', $saved['rides'][0]['vias'][0]['name']);
        self::assertSame('WAL', $saved['rides'][0]['vias'][1]['abbreviation']);
        self::assertSame('Personenextrazug (NON-PUBLIC)', $saved['rides'][0]['rideType']['name']);
        self::assertSame('SBBP', $saved['rides'][0]['evu']['name']);
        self::assertSame([], $saved['warnings']);

        $updated = $ride;
        $updated['id'] = $saved['rides'][0]['id'];
        $updated['vias'] = [
            ['didokCode' => '8503125', 'name' => 'Wald', 'abbreviation' => 'WAL'],
            ['didokCode' => '8503123', 'name' => 'Bauma', 'abbreviation' => 'BAU'],
            ['didokCode' => '8503100', 'name' => 'Rüti ZH', 'abbreviation' => 'RUE'],
        ];
        $updated['evuId'] = $this->catalogId($saved['evus'], 'DVZO');
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$updated]]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        $loaded = $this->jsonBody($client);
        self::assertSame(['Wald', 'Bauma', 'Rüti ZH'], array_column($loaded['rides'][0]['vias'], 'name'));
        self::assertSame('DVZO', $loaded['rides'][0]['evu']['name']);
        self::assertSame($typeId, $loaded['rides'][0]['rideType']['id']);

        $broken = $updated;
        $broken['vias'] = [['didokCode' => 'abc', 'name' => '']];
        $broken['rideTypeId'] = '00000000-0000-0000-0000-000000000099';
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$broken]]);
        self::assertResponseStatusCodeSame(422);
        $errors = $this->jsonBody($client)['errors'];
        self::assertSame('Die Eingaben sind unvollständig.', $this->jsonBody($client)['error']);
        self::assertSame(
            'Bitte den DiDok-Code des Via-Bahnhofs aus der Suche übernehmen.',
            $errors['rides.0.vias.0.didokCode'],
        );
        self::assertSame('Bitte eine Art der Fahrt aus der Liste wählen.', $errors['rides.0.rideTypeId']);

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertSame(['Wald', 'Bauma', 'Rüti ZH'], array_column($this->jsonBody($client)['rides'][0]['vias'], 'name'));
    }

    public function testEventPlanningFieldsPersistAndCanStayUnset(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Planung am Anlass');
        $dayId = $created['days'][0]['id'];
        $vehicleId = $this->createVehicle($client);
        $ride = $this->ride($dayId, $vehicleId, '09:10', 0, null, null);

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$ride],
            'planning' => [
                'driverReported' => true,
                'pathStatus' => 'ordered',
                'hireStatus' => 'confirmed',
                'shunting' => 'Gleis 3',
                'breaks' => 'Mittag in Hinwil',
                'furtherInfo' => 'Lokführer meldet sich um 8 Uhr',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($client);
        self::assertTrue($saved['planning']['driverReported']);
        self::assertSame('ordered', $saved['planning']['pathStatus']);
        self::assertSame('bestellt', $saved['planning']['pathStatusLabel']);
        self::assertSame('confirmed', $saved['planning']['hireStatus']);
        self::assertSame('bestätigt', $saved['planning']['hireStatusLabel']);
        self::assertSame('Gleis 3', $saved['planning']['shunting']);
        self::assertSame('Mittag in Hinwil', $saved['planning']['breaks']);
        self::assertSame('Lokführer meldet sich um 8 Uhr', $saved['planning']['furtherInfo']);

        $ride['id'] = $saved['rides'][0]['id'];
        $ride['trainNumber'] = '30432';
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$ride]]);
        self::assertResponseIsSuccessful();
        self::assertSame('Gleis 3', $this->jsonBody($client)['planning']['shunting']);
        self::assertTrue($this->jsonBody($client)['planning']['driverReported']);

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$ride],
            'planning' => [
                'driverReported' => null,
                'pathStatus' => 'not_applicable',
                'hireStatus' => null,
                'shunting' => '',
                'breaks' => 'Pause kurz',
                'furtherInfo' => null,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        $loaded = $this->jsonBody($client);
        self::assertNull($loaded['planning']['driverReported']);
        self::assertSame('nicht zutreffend', $loaded['planning']['pathStatusLabel']);
        self::assertNull($loaded['planning']['hireStatus']);
        self::assertNull($loaded['planning']['shunting']);
        self::assertSame('Pause kurz', $loaded['planning']['breaks']);
        self::assertNull($loaded['planning']['furtherInfo']);
        self::assertSame('30432', $loaded['rides'][0]['trainNumber']);

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
            'rides' => [$ride],
            'planning' => [
                'driverReported' => 'ja',
                'pathStatus' => 'nope',
                'hireStatus' => 'confirmed',
                'shunting' => 'Gleis 3',
                'breaks' => '',
                'furtherInfo' => '',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Die Eingaben sind unvollständig.', $this->jsonBody($client)['error']);
        self::assertSame('Lokführer gemeldet ist Ja oder Nein.', $this->jsonBody($client)['errors']['planning.driverReported']);
        self::assertSame(
            'Trassenplanung ist nicht zutreffend, angefragt, bestellt oder angeordnet.',
            $this->jsonBody($client)['errors']['planning.pathStatus'],
        );

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertSame('Pause kurz', $this->jsonBody($client)['planning']['breaks']);
        self::assertNull($this->jsonBody($client)['planning']['driverReported']);
    }

    public function testAdminsExtendTheListsAndRejectDuplicates(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Katalog');
        $suffix = bin2hex(random_bytes(3));

        $this->request($client, 'POST', '/api/v1/ride-types', ['name' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte einen Namen angeben.', $this->jsonBody($client)['errors']['name']);

        $this->request($client, 'POST', '/api/v1/ride-types', ['name' => 'Personenextrazug (NON-PUBLIC)']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Diese Art der Fahrt gibt es bereits.', $this->jsonBody($client)['error']);

        $addedType = 'Extrazug '.$suffix;
        $this->request($client, 'POST', '/api/v1/ride-types', ['name' => $addedType]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($addedType, $this->jsonBody($client)['name']);

        $this->request($client, 'POST', '/api/v1/evus', ['name' => 'sbbp']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dieses EVU gibt es bereits.', $this->jsonBody($client)['error']);

        $addedEvu = 'EVU'.$suffix;
        $this->request($client, 'POST', '/api/v1/evus', ['name' => $addedEvu]);
        self::assertResponseStatusCodeSame(201);

        $this->request($client, 'GET', '/api/v1/ride-catalog');
        self::assertResponseIsSuccessful();
        $catalog = $this->jsonBody($client);
        self::assertNotNull($this->catalogId($catalog['rideTypes'], 'Personenextrazug (NON-PUBLIC)'));
        self::assertNotNull($this->catalogId($catalog['rideTypes'], 'Leermaterialextrazug'));
        self::assertNotNull($this->catalogId($catalog['rideTypes'], 'Lokextrazug'));
        self::assertNotNull($this->catalogId($catalog['rideTypes'], $addedType));
        self::assertNotNull($this->catalogId($catalog['evus'], 'SBBP'));
        self::assertNotNull($this->catalogId($catalog['evus'], 'TR'));
        self::assertNotNull($this->catalogId($catalog['evus'], 'RAILH'));
        self::assertNotNull($this->catalogId($catalog['evus'], 'DVZO'));
        self::assertNotNull($this->catalogId($catalog['evus'], $addedEvu));

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody($client);
        self::assertTrue($body['canManageCatalogs']);
        self::assertNotNull($this->catalogId($body['rideTypes'], $addedType));
        self::assertNotNull($this->catalogId($body['evus'], $addedEvu));
    }

    public function testRidePlannerUsesTheListsButCannotExtendThem(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Planer');
        $dayId = $created['days'][0]['id'];
        $vehicleId = $this->createVehicle($client);
        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        $typeId = $this->catalogId($this->jsonBody($client)['rideTypes'], 'Lokextrazug');
        $evuId = $this->catalogId($this->jsonBody($client)['evus'], 'TR');

        $this->withGroups('Fahrtenplanung', function (KernelBrowser $planner) use ($created, $dayId, $vehicleId, $typeId, $evuId): void {
            $this->request($planner, 'GET', '/api/v1/ride-catalog');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Die Fahrtenlisten sehen nur Administratoren.', $this->jsonBody($planner)['error']);

            $this->request($planner, 'POST', '/api/v1/evus', ['name' => 'Fremd EVU']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Listen ergänzen dürfen nur Administratoren.', $this->jsonBody($planner)['error']);

            $ride = $this->ride($dayId, $vehicleId, '08:00', 0, '09:00', 0);
            $ride['rideTypeId'] = $typeId;
            $ride['evuId'] = $evuId;
            $ride['vias'] = [];
            $this->request($planner, 'PUT', '/api/v1/events/'.$created['id'].'/rides', [
                'rides' => [$ride],
                'planning' => [
                    'driverReported' => false,
                    'pathStatus' => 'requested',
                    'hireStatus' => 'requested',
                    'shunting' => null,
                    'breaks' => null,
                    'furtherInfo' => 'Nur die Fahrtenplanung',
                ],
            ]);
            self::assertResponseIsSuccessful();
            self::assertFalse($this->jsonBody($planner)['canManageCatalogs']);
            self::assertSame('Lokextrazug', $this->jsonBody($planner)['rides'][0]['rideType']['name']);
            self::assertSame('angefragt', $this->jsonBody($planner)['planning']['pathStatusLabel']);
        });
    }

    public function testSameVehicleOnTwoRidesOfOneEventDoesNotWarn(): void
    {
        $client = $this->browser();
        $vehicleId = $this->createVehicle($client, 'Be 4/6');
        $event = $this->createEvent($client, 'Zwei Fahrten ein Anlass');
        $dayId = $event['days'][0]['id'];

        $this->request($client, 'PUT', '/api/v1/events/'.$event['id'].'/rides', [
            'rides' => [
                $this->ride($dayId, $vehicleId, '09:00', 0, '12:00', 0),
                $this->ride($dayId, $vehicleId, '13:00', 0, '16:00', 0),
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->jsonBody($client)['rides']);
        self::assertSame([], $this->jsonBody($client)['warnings']);

        $this->request($client, 'GET', '/api/v1/events/'.$event['id'].'/rides');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody($client)['warnings']);
        self::assertSame($vehicleId, $this->jsonBody($client)['rides'][0]['vehicles'][0]['vehicleId']);
        self::assertSame($vehicleId, $this->jsonBody($client)['rides'][1]['vehicles'][0]['vehicleId']);
    }

    public function testInactiveCatalogStaysOnExistingRidesAndDeletesOnlyWhenUnused(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Katalogstatus');
        $dayId = $created['days'][0]['id'];
        $vehicleId = $this->createVehicle($client);
        $suffix = bin2hex(random_bytes(3));
        $typeName = 'Sonderzug '.$suffix;
        $evuName = 'EVUSTATUS'.$suffix;
        $unusedType = 'Ungenutzt '.$suffix;
        $unusedEvu = 'UNGENUTZT'.$suffix;

        $this->request($client, 'POST', '/api/v1/ride-types', ['name' => $typeName]);
        self::assertResponseStatusCodeSame(201);
        $typeId = $this->jsonBody($client)['id'];
        self::assertIsString($typeId);
        $this->request($client, 'POST', '/api/v1/evus', ['name' => $evuName]);
        self::assertResponseStatusCodeSame(201);
        $evuId = $this->jsonBody($client)['id'];
        self::assertIsString($evuId);
        $this->request($client, 'POST', '/api/v1/ride-types', ['name' => $unusedType]);
        self::assertResponseStatusCodeSame(201);
        $unusedTypeId = $this->jsonBody($client)['id'];
        self::assertIsString($unusedTypeId);
        $this->request($client, 'POST', '/api/v1/evus', ['name' => $unusedEvu]);
        self::assertResponseStatusCodeSame(201);
        $unusedEvuId = $this->jsonBody($client)['id'];
        self::assertIsString($unusedEvuId);

        $ride = $this->ride($dayId, $vehicleId, '09:00', 0, '10:00', 0);
        $ride['rideTypeId'] = $typeId;
        $ride['evuId'] = $evuId;
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$ride]]);
        self::assertResponseIsSuccessful();
        $rideId = $this->jsonBody($client)['rides'][0]['id'];
        self::assertIsString($rideId);

        $this->request($client, 'DELETE', '/api/v1/ride-types/'.$typeId);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Diese Art der Fahrt wird noch verwendet und kann nicht gelöscht werden.',
            $this->jsonBody($client)['error'],
        );
        $this->request($client, 'DELETE', '/api/v1/evus/'.$evuId);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dieses EVU wird noch verwendet und kann nicht gelöscht werden.', $this->jsonBody($client)['error']);

        $this->request($client, 'PATCH', '/api/v1/ride-types/'.$typeId, ['active' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->jsonBody($client)['active']);
        self::assertTrue($this->jsonBody($client)['used']);
        $this->request($client, 'PATCH', '/api/v1/evus/'.$evuId, ['active' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->jsonBody($client)['active']);
        self::assertTrue($this->jsonBody($client)['used']);

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        $hidden = $this->jsonBody($client);
        self::assertNull($this->catalogIdOrNull($hidden['rideTypes'], $typeName));
        self::assertNull($this->catalogIdOrNull($hidden['evus'], $evuName));
        self::assertSame($typeName, $hidden['rides'][0]['rideType']['name']);
        self::assertFalse($hidden['rides'][0]['rideType']['active']);
        self::assertSame($evuName, $hidden['rides'][0]['evu']['name']);
        self::assertFalse($hidden['rides'][0]['evu']['active']);

        $kept = $ride;
        $kept['id'] = $rideId;
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$kept]]);
        self::assertResponseIsSuccessful();
        self::assertSame($typeId, $this->jsonBody($client)['rides'][0]['rideType']['id']);
        self::assertSame($evuId, $this->jsonBody($client)['rides'][0]['evu']['id']);

        $fresh = $this->ride($dayId, $vehicleId, '11:00', 0, '12:00', 0);
        $fresh['trainNumber'] = '99901';
        $fresh['rideTypeId'] = $typeId;
        $fresh['evuId'] = $evuId;
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'].'/rides', ['rides' => [$kept, $fresh]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Diese Art der Fahrt ist nicht mehr aktiv.', $this->jsonBody($client)['errors']['rides.1.rideTypeId']);
        self::assertSame('Dieses EVU ist nicht mehr aktiv.', $this->jsonBody($client)['errors']['rides.1.evuId']);

        $this->request($client, 'GET', '/api/v1/events/'.$created['id'].'/rides');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonBody($client)['rides']);
        self::assertSame($typeName, $this->jsonBody($client)['rides'][0]['rideType']['name']);

        $this->request($client, 'DELETE', '/api/v1/ride-types/'.$unusedTypeId);
        self::assertResponseStatusCodeSame(204);
        $this->request($client, 'DELETE', '/api/v1/evus/'.$unusedEvuId);
        self::assertResponseStatusCodeSame(204);

        $this->request($client, 'POST', '/api/v1/ride-types', ['name' => $typeName]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Diese Art der Fahrt gibt es bereits.', $this->jsonBody($client)['error']);
        $this->request($client, 'POST', '/api/v1/evus', ['name' => strtolower($evuName)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dieses EVU gibt es bereits.', $this->jsonBody($client)['error']);

        $this->request($client, 'GET', '/api/v1/ride-catalog');
        self::assertResponseIsSuccessful();
        $catalog = $this->jsonBody($client);
        $storedType = $this->catalogEntry($catalog['rideTypes'], $typeName);
        $storedEvu = $this->catalogEntry($catalog['evus'], $evuName);
        self::assertFalse($storedType['active']);
        self::assertTrue($storedType['used']);
        self::assertFalse($storedEvu['active']);
        self::assertTrue($storedEvu['used']);
        self::assertNull($this->catalogIdOrNull($catalog['rideTypes'], $unusedType));
        self::assertNull($this->catalogIdOrNull($catalog['evus'], $unusedEvu));

        $this->withGroups('Fahrtenplanung', function (KernelBrowser $planner) use ($typeId, $evuId): void {
            $this->request($planner, 'DELETE', '/api/v1/ride-types/'.$typeId);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Listen ändern dürfen nur Administratoren.', $this->jsonBody($planner)['error']);
            $this->request($planner, 'PATCH', '/api/v1/evus/'.$evuId, ['active' => true]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Listen ändern dürfen nur Administratoren.', $this->jsonBody($planner)['error']);
        });
    }

    /** @param list<array{id: string, name: string}> $items */
    private function catalogId(array $items, string $name): string
    {
        foreach ($items as $item) {
            if ($item['name'] === $name) {
                return $item['id'];
            }
        }
        self::fail('Der Listenwert '.$name.' fehlt.');
    }

    /** @param list<array<string, mixed>> $items */
    private function catalogIdOrNull(array $items, string $name): ?string
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name && is_string($item['id'] ?? null)) {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function catalogEntry(array $items, string $name): array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }
        self::fail('Der Listenwert '.$name.' fehlt.');
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
    private function createEvent(KernelBrowser $client, string $title, string $status = 'published', string $date = '2026-12-05'): array
    {
        $this->request($client, 'POST', '/api/v1/events', [
            'title' => $title,
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
            'status' => $status,
            'days' => [[
                'date' => $date,
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

    private function createExternalVehicle(KernelBrowser $client, string $name): string
    {
        $this->request($client, 'POST', '/api/v1/vehicles', [
            'name' => $name,
            'number' => null,
            'status' => 'ready',
            'tracksMotorHours' => false,
            'technicalNote' => null,
            'external' => true,
            'owner' => 'Vermieter',
            'nvr' => 'NVR-1',
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody($client)['id'];
        self::assertIsString($id);
        self::assertTrue($this->jsonBody($client)['external']);

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
