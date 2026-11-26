<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventCategoryRepository
{
    public function add(EventCategory $category): void;

    public function get(string $id): ?EventCategory;

    public function byName(string $name): ?EventCategory;

    /** @return list<EventCategory> */
    public function all(): array;

    public function nextSortOrder(): int;

    public function remove(EventCategory $category): void;

    public function eventCount(EventCategory $category): int;
}
