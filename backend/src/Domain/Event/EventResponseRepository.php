<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventResponseRepository
{
    /** @return list<EventResponse> */
    public function bySpondMemberId(string $spondMemberId): array;

    /** @return list<string> */
    public function idsForEvent(string $eventId): array;
}
