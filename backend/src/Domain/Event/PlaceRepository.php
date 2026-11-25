<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface PlaceRepository
{
    public function add(Place $place): void;

    public function byName(string $name): ?Place;

    /** @return list<Place> */
    public function all(): array;
}
