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
use App\Domain\Ride\EventRidePlan;
use App\Domain\Ride\EventRidePlanRepository;
use App\Domain\Ride\Evu;
use App\Domain\Ride\EvuRepository;
use App\Domain\Ride\HireStatus;
use App\Domain\Ride\PathStatus;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideRepository;
use App\Domain\Ride\RideType;
use App\Domain\Ride\RideTypeRepository;
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
        private RideTypeRepository $rideTypes,
        private EvuRepository $evus,
        private EventRidePlanRepository $plans,
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
        $eventBefore = $event->toAudit();
        $planningErrors = [];
        $planning = array_key_exists('planning', $payload) ? $this->normalizePlanning($payload['planning'], $planningErrors) : null;
        $fleet = [];
        foreach ($this->vehicles->all() as $vehicle) {
            $fleet[$vehicle->id()] = $vehicle;
        }
        if (array_key_exists('eventVehicles', $payload)) {
            $event->replaceAssignedVehicles($this->eventVehicles($payload['eventVehicles'], $fleet));
        }
        try {
            $input = $this->normalize($event, $payload, $fleet);
        } catch (InvalidRequest $invalid) {
            throw new InvalidRequest($invalid->errors + $planningErrors);
        }
        if ($planningErrors !== []) {
            throw new InvalidRequest($planningErrors);
        }
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
                $this->applyRideExtras($current, $row);
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
            $this->applyRideExtras($created, $row, true);
            $this->rides->add($created);
            $creates[] = $created;
            $saved[] = $created;
        }

        $warnings = $this->overlap->warnings($saved, $this->rides->withOwnVehiclesExcept($event->id()));
        $now = $this->clock->now();
        if ($planning !== null) {
            $this->storePlanning($actor, $event, $planning, $now);
        }
        foreach ($creates as $ride) {
            $this->audit->record($actor->getUserIdentifier(), 'ride', $ride->id(), 'create', null, $ride->toAudit(), $now, $actor->displayName());
        }
        foreach ($updates as $update) {
            $after = $update['ride']->toAudit();
            if ($update['before'] === $after) {
                continue;
            }
            $this->audit->record(
                $actor->getUserIdentifier(),
                'ride',
                $update['ride']->id(),
                'update',
                $update['before'],
                $after,
                $now,
                $actor->displayName(),
            );
        }
        foreach ($existing as $id => $ride) {
            if (isset($kept[$id])) {
                continue;
            }
            $this->audit->record($actor->getUserIdentifier(), 'ride', $ride->id(), 'delete', $ride->toAudit(), null, $now, $actor->displayName());
            $this->rides->remove($ride);
        }
        $eventAfter = $event->toAudit();
        if ($eventBefore !== $eventAfter) {
            $this->audit->record($actor->getUserIdentifier(), 'event', $event->id(), 'update', $eventBefore, $eventAfter, $now, $actor->displayName());
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
     *     applyVias: bool,
     *     vias: list<array{didokCode: string, name: string, abbreviation: ?string}>,
     *     applyRideType: bool,
     *     rideType: ?RideType,
     *     applyEvu: bool,
     *     evu: ?Evu,
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
        $rideTypeIndex = $this->indexedRideTypes();
        $evuIndex = $this->indexedEvus();
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

            $currentRide = $id !== '' ? $this->rides->get($id) : null;
            if (!$currentRide instanceof Ride || $currentRide->event()->id() !== $event->id()) {
                $currentRide = null;
            }
            $origin = $this->station($ride['origin'] ?? null, 'rides.'.$index.'.origin', 'Startbahnhof', $errors);
            $destination = $this->station($ride['destination'] ?? null, 'rides.'.$index.'.destination', 'Zielbahnhof', $errors);
            $viaChoice = $this->vias($ride, $index, $errors);
            $rideTypeChoice = $this->catalogChoice($ride, 'rideTypeId', $rideTypeIndex, 'rides.'.$index.'.rideTypeId', 'Bitte eine Art der Fahrt aus der Liste wählen.', 'Diese Art der Fahrt ist nicht mehr aktiv.', $currentRide?->rideType()?->id(), $errors);
            $evuChoice = $this->catalogChoice($ride, 'evuId', $evuIndex, 'rides.'.$index.'.evuId', 'Bitte ein EVU aus der Liste wählen.', 'Dieses EVU ist nicht mehr aktiv.', $currentRide?->evu()?->id(), $errors);
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

            if (!$day instanceof EventDay || $origin === null || $destination === null || $vehicles === null || $viaChoice === null || $trainNumber === '' || mb_strlen($trainNumber) > 20) {
                continue;
            }
            if ($this->hasFieldErrors($errors, (string) $index)) {
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
                'applyVias' => $viaChoice['apply'],
                'vias' => $viaChoice['stations'],
                'applyRideType' => $rideTypeChoice['apply'],
                'rideType' => $rideTypeChoice['value'],
                'applyEvu' => $evuChoice['apply'],
                'evu' => $evuChoice['value'],
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
     * @param array{applyVias: bool, vias: list<array{didokCode: string, name: string, abbreviation: ?string}>, applyRideType: bool, rideType: mixed, applyEvu: bool, evu: mixed} $row
     */
    private function applyRideExtras(Ride $ride, array $row, bool $creating = false): void
    {
        if ($row['applyVias'] || $creating) {
            $ride->replaceVias($row['vias']);
        }
        if ($row['applyRideType'] || $creating) {
            $rideType = $row['rideType'];
            $ride->chooseType($rideType instanceof RideType ? $rideType : null);
        }
        if ($row['applyEvu'] || $creating) {
            $evu = $row['evu'];
            $ride->chooseEvu($evu instanceof Evu ? $evu : null);
        }
    }

    /**
     * @param array{
     *     driverReported: ?bool,
     *     pathStatus: ?PathStatus,
     *     hireStatus: ?HireStatus,
     *     shunting: ?string,
     *     breaks: ?string,
     *     furtherInfo: ?string
     * } $planning
     */
    private function storePlanning(MemberUser $actor, Event $event, array $planning, \DateTimeImmutable $now): void
    {
        $current = $this->plans->forEvent($event->id());
        $empty = $planning['driverReported'] === null
            && $planning['pathStatus'] === null
            && $planning['hireStatus'] === null
            && $planning['shunting'] === null
            && $planning['breaks'] === null
            && $planning['furtherInfo'] === null;
        if (!$current instanceof EventRidePlan && $empty) {
            return;
        }

        if (!$current instanceof EventRidePlan) {
            $current = new EventRidePlan($event, $now);
            $this->plans->add($current);
            $before = null;
            $action = 'create';
        } else {
            $before = $current->toAudit();
            $action = 'update';
        }

        $current->revise(
            $planning['driverReported'],
            $planning['pathStatus'],
            $planning['hireStatus'],
            $planning['shunting'],
            $planning['breaks'],
            $planning['furtherInfo'],
            $now,
        );
        $after = $current->toAudit();
        if ($before === $after) {
            return;
        }

        $this->audit->record($actor->getUserIdentifier(), 'event_ride_plan', $current->id(), $action, $before, $after, $now, $actor->displayName());
    }

    /**
     * @return array{
     *     driverReported: ?bool,
     *     pathStatus: ?PathStatus,
     *     hireStatus: ?HireStatus,
     *     shunting: ?string,
     *     breaks: ?string,
     *     furtherInfo: ?string
     * }
     */
    /**
     * @param array<string, string> $errors
     */
    private function normalizePlanning(mixed $value, array &$errors): ?array
    {
        if (!is_array($value)) {
            $errors['planning'] = 'Die Fahrtenplanung ist ungültig.';

            return null;
        }

        $driverReported = $this->optionalBool($value['driverReported'] ?? null, 'planning.driverReported', 'Lokführer gemeldet ist Ja oder Nein.', $errors);
        $pathStatus = $this->optionalStatus($value['pathStatus'] ?? null, PathStatus::class, 'planning.pathStatus', 'Trassenplanung ist nicht zutreffend, angefragt, bestellt oder angeordnet.', $errors);
        $hireStatus = $this->optionalStatus($value['hireStatus'] ?? null, HireStatus::class, 'planning.hireStatus', 'Fahrzeugmiete ist nicht zutreffend, angefragt, bestellt oder bestätigt.', $errors);
        $shunting = $this->optionalText($value['shunting'] ?? null, 'planning.shunting', 'Rangierplanung', $errors);
        $breaks = $this->optionalText($value['breaks'] ?? null, 'planning.breaks', 'Pausen', $errors);
        $furtherInfo = $this->optionalText($value['furtherInfo'] ?? null, 'planning.furtherInfo', 'Weitere Infos', $errors);
        if ($errors !== []) {
            return null;
        }

        return [
            'driverReported' => $driverReported,
            'pathStatus' => $pathStatus,
            'hireStatus' => $hireStatus,
            'shunting' => $shunting,
            'breaks' => $breaks,
            'furtherInfo' => $furtherInfo,
        ];
    }

    /** @param array<string, string> $errors */
    private function optionalBool(mixed $value, string $key, string $message, array &$errors): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_bool($value)) {
            $errors[$key] = $message;

            return null;
        }

        return $value;
    }

    /**
     * @param class-string<PathStatus|HireStatus> $enum
     * @param array<string, string> $errors
     */
    private function optionalStatus(mixed $value, string $enum, string $key, string $message, array &$errors): PathStatus|HireStatus|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $errors[$key] = $message;

            return null;
        }
        $status = match ($enum) {
            PathStatus::class => PathStatus::tryFrom($value),
            HireStatus::class => HireStatus::tryFrom($value),
            default => null,
        };
        if (!$status instanceof PathStatus && !$status instanceof HireStatus) {
            $errors[$key] = $message;

            return null;
        }

        return $status;
    }

    /** @param array<string, string> $errors */
    private function optionalText(mixed $value, string $key, string $label, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $errors[$key] = 'Bitte '.$label.' als Text angeben.';

            return null;
        }
        $text = trim($value);
        if (mb_strlen($text) > 4000) {
            $errors[$key] = $label.' darf höchstens 4000 Zeichen haben.';

            return null;
        }

        return $text !== '' ? $text : null;
    }

    /**
     * @param array<string, mixed> $ride
     * @param array<string, string> $errors
     * @return array{apply: bool, stations: list<array{didokCode: string, name: string, abbreviation: ?string}>}|null
     */
    private function vias(array $ride, int|string $index, array &$errors): ?array
    {
        if (!array_key_exists('vias', $ride)) {
            return ['apply' => false, 'stations' => []];
        }
        $value = $ride['vias'];
        if ($value === null) {
            return ['apply' => true, 'stations' => []];
        }
        if (!is_array($value)) {
            $errors['rides.'.$index.'.vias'] = 'Die Via-Bahnhöfe sind ungültig.';

            return null;
        }

        $stations = [];
        $valid = true;
        foreach ($value as $viaIndex => $via) {
            $station = $this->station($via, 'rides.'.$index.'.vias.'.$viaIndex, 'Via-Bahnhof', $errors);
            if ($station === null) {
                $valid = false;
                continue;
            }
            $stations[] = $station;
        }
        if (!$valid) {
            return null;
        }

        return ['apply' => true, 'stations' => $stations];
    }

    /**
     * @template T of object
     * @param array<string, mixed> $ride
     * @param array<string, T> $known
     * @param array<string, string> $errors
     * @return array{apply: bool, value: T|null}
     */
    private function catalogChoice(array $ride, string $field, array $known, string $errorKey, string $message, string $inactiveMessage, ?string $currentId, array &$errors): array
    {
        if (!array_key_exists($field, $ride)) {
            return ['apply' => false, 'value' => null];
        }
        $value = $ride[$field];
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['apply' => true, 'value' => null];
        }
        $id = trim((string) $value);
        if (!isset($known[$id])) {
            $errors[$errorKey] = $message;

            return ['apply' => true, 'value' => null];
        }
        $chosen = $known[$id];
        $active = match (true) {
            $chosen instanceof RideType => $chosen->isActive(),
            $chosen instanceof Evu => $chosen->isActive(),
            default => true,
        };
        if (!$active && $id !== $currentId) {
            $errors[$errorKey] = $inactiveMessage;

            return ['apply' => true, 'value' => null];
        }

        return ['apply' => true, 'value' => $chosen];
    }

    /** @return array<string, RideType> */
    private function indexedRideTypes(): array
    {
        $indexed = [];
        foreach ($this->rideTypes->all() as $rideType) {
            $indexed[$rideType->id()] = $rideType;
        }

        return $indexed;
    }

    /** @return array<string, Evu> */
    private function indexedEvus(): array
    {
        $indexed = [];
        foreach ($this->evus->all() as $evu) {
            $indexed[$evu->id()] = $evu;
        }

        return $indexed;
    }

    /** @param array<string, string> $errors */
    private function hasFieldErrors(array $errors, string $index): bool
    {
        $prefix = 'rides.'.$index.'.';
        foreach (array_keys($errors) as $key) {
            if ($key === 'rides.'.$index || str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
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
