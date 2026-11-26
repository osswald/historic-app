<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventHelferLinkRepository
{
    public function add(EventHelferLink $link): void;

    public function remove(EventHelferLink $link): void;

    public function forEvent(string $eventId): ?EventHelferLink;
}
