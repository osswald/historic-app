<?php

declare(strict_types=1);

namespace App\Application\Fleet;

use App\Application\InvalidRequest;
use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

final class RecordBatteryCharge
{
    public function __construct(
        private VehicleRepository $vehicles,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function __invoke(MemberUser $actor, string $vehicleId, array $payload): Vehicle
    {
        $vehicle = $this->vehicles->get($vehicleId);
        if (!$vehicle instanceof Vehicle) {
            throw new NotFound('Fahrzeug nicht gefunden.');
        }
        $chargedAt = $this->moment($payload['chargedOn'] ?? null);
        $now = $this->clock->now();
        $charge = $vehicle->recordBattery($actor->getUserIdentifier(), $actor->displayName(), $chargedAt);
        $this->audit->record($actor->getUserIdentifier(), 'battery_charge', $charge->id(), 'create', null, $charge->toAudit(), $now);
        $this->entityManager->flush();

        return $vehicle;
    }

    private function moment(mixed $value): \DateTimeImmutable
    {
        $raw = trim((string) $value);
        $zone = new \DateTimeZone('Europe/Zurich');
        if ($raw === '') {
            $raw = $this->clock->now()->setTimezone($zone)->format('Y-m-d');
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);
        $parsed = \DateTimeImmutable::getLastErrors();
        if (!$day instanceof \DateTimeImmutable || (is_array($parsed) && ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0))) {
            throw new InvalidRequest(['chargedOn' => 'Bitte ein gültiges Datum für die Batterieladung angeben.']);
        }
        $today = $this->clock->now()->setTimezone($zone)->format('Y-m-d');
        if ($day->format('Y-m-d') > $today) {
            throw new InvalidRequest(['chargedOn' => 'Das Datum der Batterieladung darf nicht in der Zukunft liegen.']);
        }

        return $day->setTime(12, 0);
    }
}
