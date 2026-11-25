<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventRepository
{
    public function add(Event $event): void;

    public function byId(string $id): ?Event;

    /** @return list<Event> */
    public function all(): array;
}
