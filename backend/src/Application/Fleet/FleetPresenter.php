<?php

declare(strict_types=1);

namespace App\Application\Fleet;

use App\Domain\Fleet\BatteryCharge;
use App\Domain\Fleet\MeterReading;
use App\Domain\Fleet\Vehicle;
use App\Security\MemberUser;

final class FleetPresenter
{
    /**
     * @param list<Vehicle> $vehicles
     * @return array{canEdit: bool, canViewTechnical: bool, vehicles: list<array<string, mixed>>}
     */
    public function list(array $vehicles, MemberUser $user): array
    {
        $technical = $user->canEditFleet();

        return [
            'canEdit' => $technical,
            'canViewTechnical' => $technical,
            'vehicles' => array_map(fn (Vehicle $vehicle): array => $this->one($vehicle, $user), $vehicles),
        ];
    }

    /** @return array<string, mixed> */
    public function one(Vehicle $vehicle, MemberUser $user): array
    {
        $technical = $user->canEditFleet();
        $payload = [
            'id' => $vehicle->id(),
            'name' => $vehicle->name(),
            'number' => $vehicle->number(),
            'label' => $vehicle->label(),
            'status' => $vehicle->status()->value,
            'statusLabel' => $vehicle->status()->label(),
        ];
        if (!$technical) {
            return $payload;
        }

        $charges = $vehicle->batteryCharges();
        usort($charges, static fn (BatteryCharge $left, BatteryCharge $right): int => $right->chargedAt() <=> $left->chargedAt());
        $readings = $vehicle->meterReadings();
        usort($readings, static function (MeterReading $left, MeterReading $right): int {
            $byDay = $right->readOn() <=> $left->readOn();

            return $byDay !== 0 ? $byDay : $right->recordedAt() <=> $left->recordedAt();
        });
        $payload['technicalNote'] = $vehicle->technicalNote();
        $payload['tracksMotorHours'] = $vehicle->tracksMotorHours();
        $payload['batteryCharges'] = array_map(fn (BatteryCharge $charge): array => [
            'id' => $charge->id(),
            'chargedOn' => $charge->chargedAt()->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('Y-m-d'),
            'chargedAt' => $charge->chargedAt()->format(\DateTimeInterface::ATOM),
            'recordedBy' => $charge->actorName(),
        ], $charges);
        $payload['meterReadings'] = array_map(fn (MeterReading $reading): array => [
            'id' => $reading->id(),
            'kind' => $reading->kind()->value,
            'kindLabel' => $reading->kind()->label(),
            'value' => $this->decimal($reading->reading()),
            'readOn' => $reading->readOn()->format('Y-m-d'),
            'recordedBy' => $reading->actorName(),
        ], $readings);

        return $payload;
    }

    private function decimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
