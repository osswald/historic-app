<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Domain\Event\EventStatus;
use App\Domain\Fleet\Vehicle;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideWindow;

/** Doppelbelegung vereinseigener Fahrzeuge ist eine Warnung, nie eine Sperre. */
final class ClubVehicleOverlap
{
    /**
     * @param list<Ride> $rides
     * @param list<Ride> $elsewhere
     * @return list<string>
     */
    public function warnings(array $rides, array $elsewhere): array
    {
        $subject = $rides[0] ?? null;
        if ($subject instanceof Ride && $subject->event()->status() === EventStatus::Cancelled) {
            return [];
        }
        $local = $this->pairs($this->unique($rides));
        $other = $this->pairs($this->unique($elsewhere));
        $messages = [];
        foreach ($local as $assignment) {
            foreach ($other as $candidate) {
                if (!$this->conflicts($assignment, $candidate)) {
                    continue;
                }
                $messages[] = sprintf(
                    'Das Vereinsfahrzeug „%s“ überschneidet sich mit einer Fahrt der Veranstaltung „%s“ (%s).',
                    $assignment['label'],
                    $candidate['eventTitle'],
                    $candidate['statusLabel'],
                );
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param list<Ride> $rides
     * @return list<Ride>
     */
    private function unique(array $rides): array
    {
        $byId = [];
        foreach ($rides as $ride) {
            $byId[$ride->id()] = $ride;
        }

        return array_values($byId);
    }

    /**
     * @param list<Ride> $rides
     * @return list<array{vehicleId: string, label: string, window: RideWindow, eventTitle: string, statusLabel: string}>
     */
    private function pairs(array $rides): array
    {
        $pairs = [];
        $windows = [];
        foreach ($rides as $ride) {
            $eventId = $ride->event()->id();
            if (!isset($windows[$eventId])) {
                $windows[$eventId] = RideWindow::forEvent($ride->event());
            }
            foreach ($ride->ownAssignments() as $assignment) {
                $vehicle = $assignment->vehicle();
                if (!$vehicle instanceof Vehicle || $vehicle->isExternal()) {
                    continue;
                }
                $pairs[] = [
                    'vehicleId' => $vehicle->id(),
                    'label' => $vehicle->label(),
                    'window' => $windows[$eventId],
                    'eventTitle' => $ride->event()->title(),
                    'statusLabel' => $ride->event()->status()->label(),
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param array{vehicleId: string, label: string, window: RideWindow, eventTitle: string, statusLabel: string} $left
     * @param array{vehicleId: string, label: string, window: RideWindow, eventTitle: string, statusLabel: string} $right
     */
    private function conflicts(array $left, array $right): bool
    {
        return $left['vehicleId'] === $right['vehicleId'] && $left['window']->overlaps($right['window']);
    }
}
