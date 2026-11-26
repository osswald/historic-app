<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventRepository
{
    public function add(Event $event): void;

    public function byId(string $id): ?Event;

    public function bySpondEventId(string $spondEventId): ?Event;

    /** @return list<Event> */
    public function all(): array;
}
