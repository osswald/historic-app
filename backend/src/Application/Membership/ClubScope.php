<?php

declare(strict_types=1);

namespace App\Application\Membership;

use App\Domain\Event\Club;
use App\Security\MemberUser;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ClubScope
{
    /** @param list<string> $clubValues */
    public function assertAssignable(MemberUser $actor, array $clubValues): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        foreach ($clubValues as $value) {
            $club = Club::tryFrom($value);
            if (!$club instanceof Club || !$actor->belongsTo($club)) {
                throw new AccessDeniedException('Dieser Verein liegt ausserhalb der eigenen Zuordnung.');
            }
        }
    }
}
