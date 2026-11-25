<?php

declare(strict_types=1);

namespace App\Application\Fleet;

use App\Application\InvalidRequest;
use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Fleet\MeterKind;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class RecordMeterReading
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
        if (!$actor->canEditFleet()) {
            throw new AccessDeniedException('Den Fahrzeugstamm bearbeitet die Technische Kommission.');
        }
        $vehicle = $this->vehicles->get($vehicleId);
        if (!$vehicle instanceof Vehicle) {
            throw new NotFound('Fahrzeug nicht gefunden.');
        }

        $errors = [];
        $kind = MeterKind::tryFrom(trim((string) ($payload['kind'] ?? '')));
        if (!$kind instanceof MeterKind) {
            $errors['kind'] = 'Bitte Motorstunden oder Kilometer wählen.';
            $kind = MeterKind::Kilometres;
        }
        if ($kind === MeterKind::MotorHours && !$vehicle->tracksMotorHours()) {
            $errors['kind'] = 'Dieses Fahrzeug hat keinen Motorstunden-Zähler.';
        }
        $reading = $this->reading($payload['value'] ?? null);
        if ($reading === null) {
            $errors['value'] = 'Bitte einen Zählerstand ab 0 mit höchstens einer Nachkommastelle angeben.';
        }
        $readOnRaw = trim((string) ($payload['readOn'] ?? ''));
        $readOn = \DateTimeImmutable::createFromFormat('!Y-m-d', $readOnRaw);
        $parsed = \DateTimeImmutable::getLastErrors();
        if (!$readOn instanceof \DateTimeImmutable || (is_array($parsed) && ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0))) {
            $errors['readOn'] = 'Bitte das Ablesedatum angeben.';
            $readOn = null;
        } elseif ($readOn->format('Y-m-d') > $this->clock->today()->format('Y-m-d')) {
            $errors['readOn'] = 'Das Ablesedatum darf nicht in der Zukunft liegen.';
        }
        if ($errors !== [] || $reading === null || !$readOn instanceof \DateTimeImmutable) {
            throw new InvalidRequest($errors);
        }

        $now = $this->clock->now();
        $entry = $vehicle->recordMeter($kind, $reading, $readOn, $actor->getUserIdentifier(), $actor->displayName(), $now);
        $this->audit->record($actor->getUserIdentifier(), 'meter_reading', $entry->id(), 'create', null, $entry->toAudit(), $now);
        $this->entityManager->flush();

        return $vehicle;
    }

    private function reading(mixed $value): ?string
    {
        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_float($value)) {
            $raw = rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');
        } else {
            $raw = str_replace(',', '.', trim((string) $value));
        }
        if (preg_match('/^\d{1,10}(\.\d)?$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }
}
