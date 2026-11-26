<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventTaskListRepository
{
    public function add(EventTaskListLink $link): void;

    public function findForEvent(string $eventId, string $taskListId): ?EventTaskListLink;

    /** @return list<EventTaskListLink> */
    public function forEvent(string $eventId): array;
}
