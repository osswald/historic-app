<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\EventCategory;
use App\Domain\Event\EventCategoryRepository;

/** Findet eine vom Produkt vergebene Kategorie oder legt sie an, wenn sie noch fehlt. */
final class KnownEventCategories
{
    /** @var array<string, EventCategory> */
    private array $resolved = [];

    private int $created = 0;

    public function __construct(private EventCategoryRepository $categories)
    {
    }

    public function named(string $name): EventCategory
    {
        $key = mb_strtolower($name);
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $existing = $this->categories->byName($name);
        if ($existing instanceof EventCategory) {
            $this->resolved[$key] = $existing;

            return $existing;
        }

        $created = new EventCategory($name, $this->categories->nextSortOrder() + $this->created);
        ++$this->created;
        $this->categories->add($created);
        $this->resolved[$key] = $created;

        return $created;
    }
}
