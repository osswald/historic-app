<?php

declare(strict_types=1);

namespace App\Application\Fleet;

use App\Application\InvalidRequest;
use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Domain\Fleet\VehicleStatus;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SaveVehicle
{
    public function __construct(
        private VehicleRepository $vehicles,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(MemberUser $actor, array $payload): Vehicle
    {
        $this->assertFleet($actor);
        $input = $this->normalize($payload);
        $now = $this->clock->now();
        $vehicle = new Vehicle(
            $input['name'],
            $input['number'],
            $input['status'],
            $input['technicalNote'],
            $input['tracksMotorHours'],
            count($this->vehicles->all()),
            $now,
            null,
            $input['external'],
            $input['owner'],
            $input['nvr'],
            $input['p1On'],
            $input['p5On'],
            $input['oilOn'],
            $input['nextMaintenanceOn'],
        );
        $this->vehicles->add($vehicle);
        $this->audit->record($actor->getUserIdentifier(), 'vehicle', $vehicle->id(), 'create', null, $vehicle->toAudit(), $now, $actor->displayName());
        $this->entityManager->flush();

        return $vehicle;
    }

    /** @param array<string, mixed> $payload */
    public function update(MemberUser $actor, string $id, array $payload): Vehicle
    {
        $this->assertFleet($actor);
        $vehicle = $this->vehicles->get($id);
        if (!$vehicle instanceof Vehicle) {
            throw new NotFound('Fahrzeug nicht gefunden.');
        }
        $before = $vehicle->toAudit();
        $input = $this->normalize($payload);
        $now = $this->clock->now();
        $vehicle->change(
            $input['name'],
            $input['number'],
            $input['status'],
            $input['technicalNote'],
            $input['tracksMotorHours'],
            $input['external'],
            $input['owner'],
            $input['nvr'],
            $input['p1On'],
            $input['p5On'],
            $input['oilOn'],
            $input['nextMaintenanceOn'],
            $now,
        );
        $this->audit->record($actor->getUserIdentifier(), 'vehicle', $vehicle->id(), 'update', $before, $vehicle->toAudit(), $now, $actor->displayName());
        $this->entityManager->flush();

        return $vehicle;
    }

    private function assertFleet(MemberUser $actor): void
    {
        if (!$actor->canEditFleet()) {
            throw new AccessDeniedException('Den Fahrzeugstamm bearbeitet die Technische Kommission.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     name: string,
     *     number: ?string,
     *     status: VehicleStatus,
     *     technicalNote: ?string,
     *     tracksMotorHours: bool,
     *     external: bool,
     *     owner: ?string,
     *     nvr: ?string,
     *     p1On: ?\DateTimeImmutable,
     *     p5On: ?\DateTimeImmutable,
     *     oilOn: ?\DateTimeImmutable,
     *     nextMaintenanceOn: ?\DateTimeImmutable
     * }
     */
    private function normalize(array $payload): array
    {
        $errors = [];
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Bitte einen Namen mit höchstens 120 Zeichen angeben.';
        }
        $number = trim((string) ($payload['number'] ?? ''));
        if (mb_strlen($number) > 40) {
            $errors['number'] = 'Bitte höchstens 40 Zeichen für die Nummer angeben.';
        }
        $status = VehicleStatus::tryFrom(trim((string) ($payload['status'] ?? '')));
        if (!$status instanceof VehicleStatus) {
            $errors['status'] = 'Bitte Einsatzbereit, Eingeschränkt oder Gesperrt wählen.';
            $status = VehicleStatus::Ready;
        }
        $note = trim((string) ($payload['technicalNote'] ?? ''));
        if (mb_strlen($note) > 4000) {
            $errors['technicalNote'] = 'Die technische Notiz darf höchstens 4000 Zeichen haben.';
        }
        if (($status === VehicleStatus::Limited || $status === VehicleStatus::Blocked) && $note === '') {
            $errors['technicalNote'] = 'Bitte bei Eingeschränkt und Gesperrt einen Grund angeben.';
        }
        $owner = trim((string) ($payload['owner'] ?? ''));
        $nvr = trim((string) ($payload['nvr'] ?? ''));
        if (mb_strlen($owner) > 200) {
            $errors['owner'] = 'Bitte höchstens 200 Zeichen für den Eigentümerkontakt angeben.';
        }
        if (mb_strlen($nvr) > 80) {
            $errors['nvr'] = 'Bitte höchstens 80 Zeichen für die NVR angeben.';
        }
        $p1On = $this->day($payload['p1On'] ?? null, 'p1On', 'P1', $errors);
        $p5On = $this->day($payload['p5On'] ?? null, 'p5On', 'P5', $errors);
        $oilOn = $this->day($payload['oilOn'] ?? null, 'oilOn', 'Öl', $errors);
        $nextMaintenanceOn = $this->day($payload['nextMaintenanceOn'] ?? null, 'nextMaintenanceOn', 'das nächste Wartungsdatum', $errors);
        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return [
            'name' => $name,
            'number' => $number !== '' ? $number : null,
            'status' => $status,
            'technicalNote' => $note !== '' ? $note : null,
            'tracksMotorHours' => $payload['tracksMotorHours'] === true,
            'external' => ($payload['external'] ?? false) === true,
            'owner' => $owner !== '' ? $owner : null,
            'nvr' => $nvr !== '' ? $nvr : null,
            'p1On' => $p1On,
            'p5On' => $p5On,
            'oilOn' => $oilOn,
            'nextMaintenanceOn' => $nextMaintenanceOn,
        ];
    }

    /** @param array<string, string> $errors */
    private function day(mixed $value, string $key, string $label, array &$errors): ?\DateTimeImmutable
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $parsed = \DateTimeImmutable::getLastErrors();
        if (!$day instanceof \DateTimeImmutable || (is_array($parsed) && ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0))) {
            $errors[$key] = 'Bitte für '.$label.' ein gültiges Datum angeben.';

            return null;
        }

        return $day;
    }
}
