<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Domain\Ride\Ride;

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
        $club = array_values(array_filter($rides, static fn (Ride $ride): bool => $ride->clubVehicle()));
        $messages = [];
        $count = count($club);
        for ($index = 0; $index < $count; ++$index) {
            for ($otherIndex = $index + 1; $otherIndex < $count; ++$otherIndex) {
                if ($this->conflicts($club[$index], $club[$otherIndex])) {
                    $messages[] = sprintf(
                        'Das Vereinsfahrzeug „%s“ ist in dieser Veranstaltung doppelt eingeteilt.',
                        $this->label($club[$index]),
                    );
                }
            }
            foreach ($elsewhere as $other) {
                if (!$other->clubVehicle() || !$this->conflicts($club[$index], $other)) {
                    continue;
                }
                $messages[] = sprintf(
                    'Das Vereinsfahrzeug „%s“ überschneidet sich mit einer Fahrt der Veranstaltung „%s“.',
                    $this->label($club[$index]),
                    $other->event()->title(),
                );
            }
        }

        return array_values(array_unique($messages));
    }

    private function conflicts(Ride $left, Ride $right): bool
    {
        if ($this->vehicleKey($left->vehicleLabel()) !== $this->vehicleKey($right->vehicleLabel())) {
            return false;
        }

        return $left->window()->overlaps($right->window());
    }

    private function vehicleKey(string $label): string
    {
        return mb_strtolower(trim($label));
    }

    private function label(Ride $ride): string
    {
        $label = trim($ride->vehicleLabel());

        return $label === '' ? 'ohne Bezeichnung' : $label;
    }
}
