<?php

declare(strict_types=1);

namespace App\Domain\Ride;

interface RideTypeRepository
{
    public function add(RideType $rideType): void;

    public function get(string $id): ?RideType;

    public function byName(string $name): ?RideType;

    /** @return list<RideType> */
    public function all(): array;

    public function nextSortOrder(): int;
}
