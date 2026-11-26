<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Event;
use App\Security\MemberUser;

/** People already stored on attendance or invitations. No directory outside the app. */
final class KnownPeople
{
    public function __construct(private EventCatalog $events)
    {
    }

    /** @return list<array{sub: string, name: string}> */
    public function forMember(MemberUser $user): array
    {
        $people = $this->index($user);
        uasort($people, static fn (string $left, string $right): int => strcasecmp($left, $right));
        $rows = [];
        foreach ($people as $sub => $name) {
            $rows[] = ['sub' => $sub, 'name' => $name];
        }

        return $rows;
    }

    public function name(MemberUser $user, ?Event $event, string $sub): ?string
    {
        $known = $this->index($user);
        if (isset($known[$sub])) {
            return $known[$sub];
        }
        if (!$event instanceof Event) {
            return null;
        }
        foreach ([...$event->invitedPeople(), ...$event->excludedPeople()] as $person) {
            if ($person['sub'] === $sub) {
                return $person['name'];
            }
        }
        foreach ($event->days() as $day) {
            foreach ($day->responses() as $response) {
                if ($response->auth0Sub() === $sub) {
                    return $response->respondentName();
                }
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function index(MemberUser $user): array
    {
        $people = [];
        foreach ($this->events->visibleTo($user) as $event) {
            foreach ([...$event->invitedPeople(), ...$event->excludedPeople()] as $person) {
                $people[$person['sub']] = $person['name'];
            }
            foreach ($event->days() as $day) {
                foreach ($day->responses() as $response) {
                    $people[$response->auth0Sub()] = $response->respondentName();
                }
            }
        }

        return $people;
    }
}
