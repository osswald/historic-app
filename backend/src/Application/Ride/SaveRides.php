<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Domain\Ride\DayOffset;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideRepository;
use App\Domain\Ride\RideWindow;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SaveRides
{
    public function __construct(
        private EventCatalog $events,
        private RideRepository $rides,
        private VehicleRepository $vehicles,
        private ClubVehicleOverlap $overlap,
        private RidePresenter $presenter,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{canEdit: bool, warnings: list<string>, rides: list<array<string, mixed>>, ownVehicles: list<array<string, mixed>>}
     */
    public function replace(MemberUser $actor, string $eventId, array $payload): array
    {
        if (!$actor->canPlanRides()) {
            throw new AccessDeniedException('Fahrten bearbeiten dürfen Administratoren und die Fahrtenplanung.');
        }

        $event = $this->events->visible($eventId, $actor);
        $fleet = [];
        foreach ($this->vehicles->all() as $vehicle) {
            $fleet[$vehicle->id()] = $vehicle;
        }
        if (array_key_exists('eventVehicles', $payload)) {
            $event->replaceAssignedVehicles($this->eventVehicles($payload['eventVehicles'], $fleet));
        }
        $input = $this->normalize($event, $payload, $fleet);
        if (array_key_exists('eventVehicles', $payload)) {
            $this->assertSubset($event, $input);
        } else {
            $this->includeRideVehicles($event, $input);
        }
        $existing = [];
        foreach ($this->rides->forEvent($event->id()) as $ride) {
            $existing[$ride->id()] = $ride;
        }

        $kept = [];
        $saved = [];
        /** @var list<array{ride: Ride, before: array<string, mixed>}> $updates */
        $updates = [];
        /** @var list<Ride> $creates */
        $creates = [];
        foreach ($input as $row) {
            $current = $row['id'] !== null ? ($existing[$row['id']] ?? null) : null;
            if ($current instanceof Ride) {
                $before = $current->toAudit();
                $current->revise(
                    $row['day'],
                    $row['departureTime'],
                    $row['departureOffset'],
                    $row['arrivalTime'],
                    $row['arrivalOffset'],
                    $row['trainNumber'],
                    $row['originDidokCode'],
                    $row['originName'],
                    $row['originAbbreviation'],
                    $row['destinationDidokCode'],
                    $row['destinationName'],
                    $row['destinationAbbreviation'],
                    $row['internalNote'],
                    $row['sortOrder'],
                );
                $current->replaceAssignments($row['vehicles']);
                $updates[] = ['ride' => $current, 'before' => $before];
                $saved[] = $current;
                $kept[$current->id()] = true;
                continue;
            }

            $created = new Ride(
                $event,
                $row['day'],
                $row['departureTime'],
                $row['departureOffset'],
                $row['arrivalTime'],
                $row['arrivalOffset'],
                $row['trainNumber'],
                $row['originDidokCode'],
                $row['originName'],
                $row['originAbbreviation'],
                $row['destinationDidokCode'],
                $row['destinationName'],
                $row['destinationAbbreviation'],
                $row['internalNote'],
                $row['sortOrder'],
            );
            $created->replaceAssignments($row['vehicles']);
            $this->rides->add($created);
            $creates[] = $created;
            $saved[] = $created;
        }

        $warnings = $this->overlap->warnings($saved, $this->rides->withOwnVehiclesExcept($event->id()));
        $now = $this->clock->now();
        foreach ($creates as $ride) {
            $this->audit->record($actor->getUserIdentifier(), 'ride', $ride->id(), 'create', null, $ride->toAudit(), $now);
        }
        foreach ($updates as $update) {
            $this->audit->record(
                $actor->getUserIdentifier(),
                'ride',
                $update['ride']->id(),
                'update',
                $update['before'],
                $update['ride']->toAudit(),
                $now,
            );
        }
        foreach ($existing as $id => $ride) {
            if (isset($kept[$id])) {
                continue;
            }
            $this->audit->record($actor->getUserIdentifier(), 'ride', $ride->id(), 'delete', $ride->toAudit(), null, $now);
            $this->rides->remove($ride);
        }

        $this->entityManager->flush();

        return $this->presenter->present($event, $saved, $actor, $warnings, array_values($fleet));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, Vehicle> $fleet
     * @return list<array{
     *     id: ?string,
     *     day: EventDay,
     *     departureTime: ?\DateTimeImmutable,
     *     departureOffset: ?DayOffset,
     *     arrivalTime: ?\DateTimeImmutable,
     *     arrivalOffset: ?DayOffset,
     *     trainNumber: string,
     *     originDidokCode: string,
     *     originName: string,
     *     originAbbreviation: ?string,
     *     destinationDidokCode: string,
     *     destinationName: string,
     *     destinationAbbreviation: ?string,
     *     vehicles: list<Vehicle>,
     *     internalNote: ?string,
     *     sortOrder: int
     * }>
     */
    private function normalize(Event $event, array $payload, array $fleet): array
    {
        if (!isset($payload['rides']) || !is_array($payload['rides'])) {
            throw new InvalidRequest(['rides' => 'Bitte die Fahrten als Liste senden.']);
        }

        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($payload['rides'] as $index => $ride) {
            if (!is_array($ride)) {
                $errors['rides.'.$index] = 'Die Fahrt ist ungültig.';
                continue;
            }

            $id = trim((string) ($ride['id'] ?? ''));
            if ($id !== '') {
                if (isset($seen[$id])) {
                    $errors['rides.'.$index.'.id'] = 'Eine Fahrt ist doppelt enthalten.';
                    continue;
                }
                $seen[$id] = true;
            }

            $day = $event->day(trim((string) ($ride['eventDayId'] ?? '')));
            if (!$day instanceof EventDay) {
                $errors['rides.'.$index.'.eventDayId'] = 'Bitte einen Veranstaltungstag dieser Veranstaltung wählen.';
            }

            $departureRaw = trim((string) ($ride['departureTime'] ?? ''));
            $arrivalRaw = trim((string) ($ride['arrivalTime'] ?? ''));
            $departureTime = $departureRaw === '' ? null : $this->time($departureRaw);
            $arrivalTime = $arrivalRaw === '' ? null : $this->time($arrivalRaw);
            if ($departureRaw !== '' && !$departureTime instanceof \DateTimeImmutable) {
                $errors['rides.'.$index.'.departureTime'] = 'Bitte eine gültige Abfahrtszeit angeben.';
            }
            if ($arrivalRaw !== '' && !$arrivalTime instanceof \DateTimeImmutable) {
                $errors['rides.'.$index.'.arrivalTime'] = 'Bitte eine gültige Ankunftszeit angeben.';
            }

            $departureOffset = null;
            $arrivalOffset = null;
            if ($departureTime instanceof \DateTimeImmutable) {
                $departureOffset = $this->offset($ride['departureOffset'] ?? null);
                if (!$departureOffset instanceof DayOffset) {
                    $errors['rides.'.$index.'.departureOffset'] = 'Der Tagversatz der Abfahrt ist −1, 0 oder +1.';
                }
            }
            if ($arrivalTime instanceof \DateTimeImmutable) {
                $arrivalOffset = $this->offset($ride['arrivalOffset'] ?? null);
                if (!$arrivalOffset instanceof DayOffset) {
                    $errors['rides.'.$index.'.arrivalOffset'] = 'Der Tagversatz der Ankunft ist −1, 0 oder +1.';
                }
            }

            $trainNumber = trim((string) ($ride['trainNumber'] ?? ''));
            if ($trainNumber === '') {
                $errors['rides.'.$index.'.trainNumber'] = 'Bitte eine Zugnummer angeben.';
            } elseif (mb_strlen($trainNumber) > 20) {
                $errors['rides.'.$index.'.trainNumber'] = 'Bitte höchstens 20 Zeichen für die Zugnummer angeben.';
            }

            $origin = $this->station($ride['origin'] ?? null, 'rides.'.$index.'.origin', 'Startbahnhof', $errors);
            $destination = $this->station($ride['destination'] ?? null, 'rides.'.$index.'.destination', 'Zielbahnhof', $errors);
            $vehicles = $this->vehiclesFor($ride['vehicles'] ?? null, $index, $fleet, $errors);

            $internalNote = trim((string) ($ride['internalNote'] ?? ''));
            if (mb_strlen($internalNote) > 4000) {
                $errors['rides.'.$index.'.internalNote'] = 'Die interne Notiz darf höchstens 4000 Zeichen haben.';
            }

            if (
                $day instanceof EventDay
                && $departureOffset instanceof DayOffset
                && $arrivalOffset instanceof DayOffset
                && $departureTime instanceof \DateTimeImmutable
                && $arrivalTime instanceof \DateTimeImmutable
                && !RideWindow::on($day, $departureOffset, $departureTime, $arrivalOffset, $arrivalTime)->isOrdered()
            ) {
                $errors['rides.'.$index.'.arrivalTime'] = 'Die Ankunft muss nach der Abfahrt liegen.';
            }

            if (!$day instanceof EventDay || $origin === null || $destination === null || $vehicles === null || $trainNumber === '' || mb_strlen($trainNumber) > 20) {
                continue;
            }
            if (
                isset($errors['rides.'.$index.'.departureTime'])
                || isset($errors['rides.'.$index.'.departureOffset'])
                || isset($errors['rides.'.$index.'.arrivalTime'])
                || isset($errors['rides.'.$index.'.arrivalOffset'])
                || isset($errors['rides.'.$index.'.internalNote'])
            ) {
                continue;
            }

            $rows[] = [
                'id' => $id !== '' ? $id : null,
                'day' => $day,
                'departureTime' => $departureTime,
                'departureOffset' => $departureOffset,
                'arrivalTime' => $arrivalTime,
                'arrivalOffset' => $arrivalOffset,
                'trainNumber' => $trainNumber,
                'originDidokCode' => $origin['didokCode'],
                'originName' => $origin['name'],
                'originAbbreviation' => $origin['abbreviation'],
                'destinationDidokCode' => $destination['didokCode'],
                'destinationName' => $destination['name'],
                'destinationAbbreviation' => $destination['abbreviation'],
                'vehicles' => $vehicles,
                'internalNote' => $internalNote !== '' ? $internalNote : null,
                'sortOrder' => count($rows),
            ];
        }

        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $errors
     * @return array{didokCode: string, name: string, abbreviation: ?string}|null
     */
    private function station(mixed $value, string $prefix, string $label, array &$errors): ?array
    {
        if (!is_array($value)) {
            $errors[$prefix] = 'Bitte den '.$label.' aus der Suche wählen.';

            return null;
        }
        $code = trim((string) ($value['didokCode'] ?? ''));
        $name = trim((string) ($value['name'] ?? ''));
        $abbreviation = trim((string) ($value['abbreviation'] ?? ''));
        $valid = true;
        if (preg_match('/^\d{1,12}$/', $code) !== 1) {
            $errors[$prefix.'.didokCode'] = 'Bitte den DiDok-Code des '.$label.'s aus der Suche übernehmen.';
            $valid = false;
        }
        if ($name === '') {
            $errors[$prefix.'.name'] = 'Bitte den Namen des '.$label.'s aus der Suche übernehmen.';
            $valid = false;
        } elseif (mb_strlen($name) > 120) {
            $errors[$prefix.'.name'] = 'Bitte höchstens 120 Zeichen für den '.$label.' angeben.';
            $valid = false;
        }
        if (mb_strlen($abbreviation) > 20) {
            $errors[$prefix.'.abbreviation'] = 'Bitte höchstens 20 Zeichen für das Kürzel angeben.';
            $valid = false;
        }
        if (!$valid) {
            return null;
        }

        return ['didokCode' => $code, 'name' => $name, 'abbreviation' => $abbreviation !== '' ? $abbreviation : null];
    }

    /**
     * @param array<string, Vehicle> $fleet
     * @param array<string, string> $errors
     * @return list<Vehicle>|null
     */
    private function vehiclesFor(mixed $value, int|string $index, array $fleet, array &$errors): ?array
    {
        if (!is_array($value) || $value === []) {
            $errors['rides.'.$index.'.vehicles'] = 'Bitte mindestens ein Fahrzeug angeben.';

            return null;
        }

        $rows = [];
        $seen = [];
        $valid = true;
        foreach ($value as $vehicleIndex => $vehicle) {
            if (!is_array($vehicle)) {
                $errors['rides.'.$index.'.vehicles.'.$vehicleIndex] = 'Das Fahrzeug ist ungültig.';
                $valid = false;
                continue;
            }
            $vehicleId = trim((string) ($vehicle['vehicleId'] ?? ''));
            $fleetVehicle = $fleet[$vehicleId] ?? null;
            if (!$fleetVehicle instanceof Vehicle) {
                $errors['rides.'.$index.'.vehicles.'.$vehicleIndex.'.vehicleId'] = 'Bitte ein Fahrzeug aus dem Fahrzeugstamm wählen.';
                $valid = false;
                continue;
            }
            if (isset($seen[$vehicleId])) {
                $errors['rides.'.$index.'.vehicles.'.$vehicleIndex.'.vehicleId'] = 'Dieses Fahrzeug ist in der Fahrt bereits enthalten.';
                $valid = false;
                continue;
            }
            $seen[$vehicleId] = true;
            $rows[] = $fleetVehicle;
        }

        return $valid ? $rows : null;
    }

    /**
     * @param array<string, Vehicle> $fleet
     * @return list<array{vehicle: Vehicle, rentalStatus: ?string}>
     */
    private function eventVehicles(mixed $value, array $fleet): array
    {
        if (!is_array($value)) {
            throw new InvalidRequest(['eventVehicles' => 'Bitte die Fahrzeugliste des Anlasses als Liste angeben.']);
        }
        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) {
                $errors['eventVehicles.'.$index] = 'Das Fahrzeug ist ungültig.';
                continue;
            }
            $id = trim((string) ($row['vehicleId'] ?? ''));
            $vehicle = $fleet[$id] ?? null;
            if (!$vehicle instanceof Vehicle) {
                $errors['eventVehicles.'.$index.'.vehicleId'] = 'Bitte ein Fahrzeug aus dem Fahrzeugstamm wählen.';
                continue;
            }
            if (isset($seen[$id])) {
                $errors['eventVehicles.'.$index.'.vehicleId'] = 'Dieses Fahrzeug ist in der Liste bereits enthalten.';
                continue;
            }
            $seen[$id] = true;
            $rental = trim((string) ($row['rentalStatus'] ?? ''));
            if (mb_strlen($rental) > 80) {
                $errors['eventVehicles.'.$index.'.rentalStatus'] = 'Bitte höchstens 80 Zeichen für den Mietstatus angeben.';
                continue;
            }
            $rows[] = ['vehicle' => $vehicle, 'rentalStatus' => $rental !== '' ? $rental : null];
        }
        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return $rows;
    }

    /**
     * @param list<array{vehicles: list<Vehicle>}> $rows
     */
    private function assertSubset(Event $event, array $rows): void
    {
        $errors = [];
        foreach ($rows as $index => $row) {
            foreach ($row['vehicles'] as $vehicleIndex => $vehicle) {
                if (!$event->allowsVehicle($vehicle->id())) {
                    $errors['rides.'.$index.'.vehicles.'.$vehicleIndex.'.vehicleId'] = 'Bitte das Fahrzeug zuerst in die Fahrzeugliste des Anlasses aufnehmen.';
                }
            }
        }
        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }
    }

    /**
     * @param list<array{vehicles: list<Vehicle>}> $rows
     */
    private function includeRideVehicles(Event $event, array $rows): void
    {
        $current = [];
        $known = [];
        foreach ($event->assignedVehicles() as $link) {
            $current[] = ['vehicle' => $link->vehicle(), 'rentalStatus' => $link->rentalStatus()];
            $known[$link->vehicle()->id()] = true;
        }
        foreach ($rows as $row) {
            foreach ($row['vehicles'] as $vehicle) {
                if (isset($known[$vehicle->id()])) {
                    continue;
                }
                $current[] = ['vehicle' => $vehicle, 'rentalStatus' => null];
                $known[$vehicle->id()] = true;
            }
        }
        $event->replaceAssignedVehicles($current);
    }

    private function offset(mixed $value): ?DayOffset
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            return null;
        }

        return DayOffset::tryFrom($value);
    }

    private function time(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $value = substr($value, 0, 5);
        }
        if ($value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);
        $parsed = \DateTimeImmutable::getLastErrors();
        if (!$time instanceof \DateTimeImmutable || (is_array($parsed) && ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0))) {
            return null;
        }

        return $time;
    }
}
