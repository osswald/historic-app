<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Place;
use App\Domain\Event\PlaceRepository;

final class PlaceDirectory
{
    /** @var array<string, Place> */
    private array $known = [];

    public function __construct(private PlaceRepository $places)
    {
    }

    public function named(string $name): Place
    {
        if (isset($this->known[$name])) {
            return $this->known[$name];
        }
        $existing = $this->places->byName($name);
        if ($existing instanceof Place) {
            $this->known[$name] = $existing;

            return $existing;
        }
        $place = new Place($name);
        $this->places->add($place);
        $this->known[$name] = $place;

        return $place;
    }

    /** @return list<Place> */
    public function all(): array
    {
        return $this->places->all();
    }
}
