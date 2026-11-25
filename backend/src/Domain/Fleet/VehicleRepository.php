<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

interface VehicleRepository
{
    public function add(Vehicle $vehicle): void;

    public function get(string $id): ?Vehicle;

    /** @return list<Vehicle> */
    public function all(): array;
}
