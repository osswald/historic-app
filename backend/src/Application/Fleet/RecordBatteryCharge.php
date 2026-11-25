<?php

declare(strict_types=1);

namespace App\Application\Fleet;

use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class RecordBatteryCharge
{
    public function __construct(
        private VehicleRepository $vehicles,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    public function __invoke(MemberUser $actor, string $vehicleId): Vehicle
    {
        if (!$actor->canEditFleet()) {
            throw new AccessDeniedException('Den Fahrzeugstamm bearbeitet die Technische Kommission.');
        }
        $vehicle = $this->vehicles->get($vehicleId);
        if (!$vehicle instanceof Vehicle) {
            throw new NotFound('Fahrzeug nicht gefunden.');
        }
        $now = $this->clock->now();
        $charge = $vehicle->recordBattery($actor->getUserIdentifier(), $actor->displayName(), $now);
        $this->audit->record($actor->getUserIdentifier(), 'battery_charge', $charge->id(), 'create', null, $charge->toAudit(), $now);
        $this->entityManager->flush();

        return $vehicle;
    }
}
