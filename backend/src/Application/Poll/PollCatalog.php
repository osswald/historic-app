<?php

declare(strict_types=1);

namespace App\Application\Poll;

use App\Application\NotFound;
use App\Domain\Poll\Poll;
use App\Domain\Poll\PollRepository;
use App\Security\MemberUser;

final class PollCatalog
{
    public function __construct(private PollRepository $polls)
    {
    }

    /** @return list<Poll> */
    public function visibleTo(MemberUser $user): array
    {
        $polls = array_values(array_filter(
            $this->polls->all(),
            fn (Poll $poll): bool => $user->isAdmin() || $user->sharesClub($poll->clubs()),
        ));
        usort($polls, fn (Poll $a, Poll $b): int => [$a->sortKey(), $a->title()] <=> [$b->sortKey(), $b->title()]);

        return $polls;
    }

    public function visible(string $id, MemberUser $user): Poll
    {
        $poll = $this->polls->byId($id);
        if (!$poll instanceof Poll || (!$user->isAdmin() && !$user->sharesClub($poll->clubs()))) {
            throw new NotFound('Umfrage nicht gefunden.');
        }

        return $poll;
    }
}
