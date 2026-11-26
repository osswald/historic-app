<?php

declare(strict_types=1);

namespace App\Domain\Ride;

interface EvuRepository
{
    public function add(Evu $evu): void;

    public function get(string $id): ?Evu;

    public function byName(string $name): ?Evu;

    /** @return list<Evu> */
    public function all(): array;

    public function nextSortOrder(): int;

    public function remove(Evu $evu): void;

    public function rideCount(Evu $evu): int;
}
