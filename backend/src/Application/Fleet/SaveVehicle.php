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
        );
        $this->vehicles->add($vehicle);
        $this->audit->record($actor->getUserIdentifier(), 'vehicle', $vehicle->id(), 'create', null, $vehicle->toAudit(), $now);
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
            $now,
        );
        $this->audit->record($actor->getUserIdentifier(), 'vehicle', $vehicle->id(), 'update', $before, $vehicle->toAudit(), $now);
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
     * @return array{name: string, number: ?string, status: VehicleStatus, technicalNote: ?string, tracksMotorHours: bool}
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
        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return [
            'name' => $name,
            'number' => $number !== '' ? $number : null,
            'status' => $status,
            'technicalNote' => $note !== '' ? $note : null,
            'tracksMotorHours' => $payload['tracksMotorHours'] === true,
        ];
    }
}
