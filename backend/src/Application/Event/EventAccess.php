<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Event;
use App\Domain\Event\EventStatus;
use App\Security\MemberUser;

/**
 * Z-02 limits every view to the member's clubs. V-05 narrows that further when
 * an event names groups or people. Group membership applies to upcoming events;
 * a later group change does not open a past event. Named includes stay.
 * An empty invitation list means the assigned clubs are the audience.
 */
final class EventAccess
{
    public function canSee(Event $event, MemberUser $user, \DateTimeImmutable $today): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if (!$user->sharesClub($event->clubs())) {
            return false;
        }
        if ($event->status() === EventStatus::Draft) {
            return $user->isOrganizer();
        }
        if ($user->isOrganizer()) {
            return true;
        }

        return $this->invited($event, $user, $today);
    }

    public function invited(Event $event, MemberUser $user, \DateTimeImmutable $today): bool
    {
        if ($event->excludesPerson($user->getUserIdentifier())) {
            return false;
        }
        if (!$event->hasIncludeRules()) {
            return $user->sharesClub($event->clubs());
        }
        if ($event->includesPerson($user->getUserIdentifier())) {
            return true;
        }
        if (!$event->includesGroup($user->groups())) {
            return false;
        }

        return $event->isUpcoming($today) || $event->answeredBy($user->getUserIdentifier(), $user->spondId());
    }
}
