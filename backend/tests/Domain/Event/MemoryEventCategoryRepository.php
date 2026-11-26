<?php

declare(strict_types=1);

namespace App\Tests\Domain\Event;

use App\Domain\Event\EventCategory;
use App\Domain\Event\EventCategoryRepository;

final class MemoryEventCategoryRepository implements EventCategoryRepository
{
    /** @var array<string, EventCategory> */
    private array $items = [];

    public function add(EventCategory $category): void
    {
        $this->items[$category->id()] = $category;
    }

    public function get(string $id): ?EventCategory
    {
        return $this->items[$id] ?? null;
    }

    public function byName(string $name): ?EventCategory
    {
        foreach ($this->items as $item) {
            if (mb_strtolower($item->name()) === mb_strtolower($name)) {
                return $item;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->items);
    }

    public function nextSortOrder(): int
    {
        return count($this->items);
    }

    public function remove(EventCategory $category): void
    {
        unset($this->items[$category->id()]);
    }

    public function eventCount(EventCategory $category): int
    {
        return 0;
    }
}
