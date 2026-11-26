<?php

declare(strict_types=1);

namespace App\Application\Audit;

/** Picks a stored name, otherwise a name the app already has, otherwise the stored identifier. */
final class AuditActor
{
    public function label(?string $storedName, string $actorSub, ?string $knownName): ?string
    {
        $stored = $storedName !== null ? trim($storedName) : '';
        if ($stored !== '') {
            return $stored;
        }
        $known = $knownName !== null ? trim($knownName) : '';
        if ($known !== '') {
            return $known;
        }
        $sub = trim($actorSub);

        return $sub === '' ? null : $sub;
    }
}
