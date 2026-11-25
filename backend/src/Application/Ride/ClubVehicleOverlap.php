<?php

declare(strict_types=1);

namespace App\Application\Ride;

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
        $local = $this->pairs($this->unique($rides));
        $other = $this->pairs($this->unique($elsewhere));
        $messages = [];
        $count = count($local);
        for ($index = 0; $index < $count; ++$index) {
            for ($otherIndex = $index + 1; $otherIndex < $count; ++$otherIndex) {
                if ($this->conflicts($local[$index], $local[$otherIndex])) {
                    $messages[] = sprintf(
                        'Das Vereinsfahrzeug „%s“ ist in dieser Veranstaltung doppelt eingeteilt.',
                        $local[$index]['label'],
                    );
                }
            }
            foreach ($other as $candidate) {
                if (!$this->conflicts($local[$index], $candidate)) {
                    continue;
                }
                $messages[] = sprintf(
                    'Das Vereinsfahrzeug „%s“ überschneidet sich mit einer Fahrt der Veranstaltung „%s“.',
                    $local[$index]['label'],
                    $candidate['eventTitle'],
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
     * @return list<array{vehicleId: string, label: string, window: RideWindow, eventTitle: string}>
     */
    private function pairs(array $rides): array
    {
        $pairs = [];
        foreach ($rides as $ride) {
            foreach ($ride->ownAssignments() as $assignment) {
                $vehicle = $assignment->vehicle();
                if (!$vehicle instanceof Vehicle) {
                    continue;
                }
                $pairs[] = [
                    'vehicleId' => $vehicle->id(),
                    'label' => $vehicle->label(),
                    'window' => $ride->window(),
                    'eventTitle' => $ride->event()->title(),
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param array{vehicleId: string, label: string, window: RideWindow, eventTitle: string} $left
     * @param array{vehicleId: string, label: string, window: RideWindow, eventTitle: string} $right
     */
    private function conflicts(array $left, array $right): bool
    {
        return $left['vehicleId'] === $right['vehicleId'] && $left['window']->overlaps($right['window']);
    }
}
