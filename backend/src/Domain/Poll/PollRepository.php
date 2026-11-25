<?php

declare(strict_types=1);

namespace App\Domain\Poll;

interface PollRepository
{
    public function add(Poll $poll): void;

    public function byId(string $id): ?Poll;

    /** @return list<Poll> */
    public function all(): array;
}
