<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Event\EventResponse;
use App\Domain\Event\EventVehicle;
use App\Domain\Event\MeetingPoint;
use App\Security\MemberUser;

final class EventPresenter
{
    private const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    private const MONTHS = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];
    private const MONTHS_SHORT = [
        1 => 'JAN', 'FEB', 'MÄR', 'APR', 'MAI', 'JUN',
        'JUL', 'AUG', 'SEP', 'OKT', 'NOV', 'DEZ',
    ];

    /** @return array<string, mixed> */
    public function detail(Event $event, MemberUser $user): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Zurich'));
        $headline = $event->headlineDay($today);

        return [
            'id' => $event->id(),
            'title' => $event->title(),
            'clubs' => array_map(static fn ($club) => $club->value, $event->clubs()),
            'clubLabels' => array_map(static fn ($club) => $club->label(), $event->clubs()),
            'categories' => $event->categories(),
            'status' => $event->status()->value,
            'statusLabel' => $event->status()->label(),
            'internalDescription' => $event->internalDescription(),
            'publicDescription' => $event->publicDescription(),
            'location' => $event->location(),
            'publishToWebsite' => $event->publishToWebsite(),
            'invitations' => [
                'groups' => $event->invitedGroups(),
                'people' => $event->invitedPeople(),
                'excluded' => $event->excludedPeople(),
            ],
            'vehicles' => array_map(fn (EventVehicle $link): array => [
                'vehicleId' => $link->vehicle()->id(),
                'name' => $link->vehicle()->name(),
                'number' => $link->vehicle()->number(),
                'label' => $link->vehicle()->label(),
                'external' => $link->vehicle()->isExternal(),
                'status' => $link->vehicle()->status()->value,
                'statusLabel' => $link->vehicle()->status()->label(),
                'rentalStatus' => $link->rentalStatus(),
            ], $event->assignedVehicles()),
            'eyebrow' => $event->categories()[0] ?? 'Veranstaltung',
            'card' => $headline instanceof EventDay ? $this->cardFromDay($event, $headline, $user, $today) : null,
            'days' => array_map(fn (EventDay $day): array => $this->day($day, $user, $today), $event->days()),
        ];
    }

    /** @return array<string, mixed> */
    public function card(Event $event, MemberUser $user, \DateTimeImmutable $today): array
    {
        $headline = $event->headlineDay($today);
        if (!$headline instanceof EventDay) {
            return ['id' => $event->id(), 'title' => $event->title()];
        }

        return $this->cardFromDay($event, $headline, $user, $today);
    }

    /** @return array<string, mixed> */
    private function cardFromDay(Event $event, EventDay $day, MemberUser $user, \DateTimeImmutable $today): array
    {
        $response = $day->responseForMember($user->getUserIdentifier(), $user->spondId());

        return [
            'id' => $event->id(),
            'dayId' => $day->id(),
            'title' => $event->title(),
            'status' => $event->status()->value,
            'statusLabel' => $event->status()->label(),
            'day' => $day->occursOn()->format('j'),
            'month' => self::MONTHS_SHORT[(int) $day->occursOn()->format('n')],
            'line1' => $this->line1($day, $today),
            'line2' => $this->line2($event, $day),
            'response' => $response?->answer()->value,
            'responseLabel' => $response?->answer()->label() ?? 'Antwort offen',
        ];
    }

    /** @return array<string, mixed> */
    private function day(EventDay $day, MemberUser $user, \DateTimeImmutable $today): array
    {
        $mine = $day->responseForMember($user->getUserIdentifier(), $user->spondId());
        $yes = 0;
        $no = 0;
        $answered = [];
        $responses = [];
        foreach ($day->responses() as $response) {
            $account = $response->auth0Sub();
            if ($account !== null) {
                $answered[$account] = true;
            }
            if ($response->answer()->value === 'yes') {
                ++$yes;
            } else {
                ++$no;
            }
            $responses[] = $this->response($response);
        }
        $pending = $this->pending($day->event(), $answered);

        return [
            'id' => $day->id(),
            'date' => $day->occursOn()->format('Y-m-d'),
            'startsAt' => $day->startsAt()?->format('H:i'),
            'endsAt' => $day->endsAt()?->format('H:i'),
            'location' => $day->location(),
            'meetingPoints' => array_map(static fn (MeetingPoint $point): array => [
                'id' => $point->id(),
                'placeId' => $point->place()->id(),
                'placeName' => $point->place()->name(),
                'meetsAt' => $point->meetsAt()?->format('H:i'),
                'description' => $point->description(),
            ], $day->meetingPoints()),
            'line1' => $this->line1($day, $today),
            'myResponse' => $mine?->answer()->value,
            'myComment' => $mine?->comment(),
            'summary' => ['yes' => $yes, 'no' => $no, 'pending' => count($pending)],
            'pending' => $pending,
            'responses' => $responses,
        ];
    }

    /**
     * Named invitees without an answer. Group invitations stay dynamic and do not invent unknown members.
     *
     * @param array<string, true> $answered
     * @return list<array{name: string}>
     */
    private function pending(Event $event, array $answered): array
    {
        $excluded = [];
        foreach ($event->excludedPeople() as $person) {
            $excluded[$person['sub']] = true;
        }
        $pending = [];
        foreach ($event->invitedPeople() as $person) {
            if (isset($excluded[$person['sub']]) || isset($answered[$person['sub']])) {
                continue;
            }
            $pending[] = ['name' => $person['name']];
        }

        return $pending;
    }

    /** @return array<string, mixed> */
    private function response(EventResponse $response): array
    {
        return [
            'name' => $response->respondentName(),
            'answer' => $response->answer()->value,
            'answerLabel' => $response->answer()->label(),
            'comment' => $response->comment(),
        ];
    }

    private function line1(EventDay $day, \DateTimeImmutable $today): string
    {
        $date = $day->occursOn();
        $text = sprintf(
            '%s, %d. %s',
            self::WEEKDAYS[(int) $date->format('w')],
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n')],
        );
        if ($date->format('Y') !== $today->format('Y')) {
            $text .= ' '.$date->format('Y');
        }
        $time = $this->timeRange($day);
        if ($time !== null) {
            $text .= ' · '.$time;
        }

        return $text;
    }

    private function line2(Event $event, EventDay $day): string
    {
        $parts = [];
        if ($event->categories() !== []) {
            $parts[] = implode(', ', $event->categories());
        }
        $location = $day->location() ?: $event->location();
        if ($location !== null && $location !== '') {
            $parts[] = $location;
        }

        return implode(' · ', $parts);
    }

    private function timeRange(EventDay $day): ?string
    {
        $start = $day->startsAt()?->format('H:i');
        $end = $day->endsAt()?->format('H:i');
        if ($start !== null && $end !== null) {
            return $start.'–'.$end;
        }

        return $start ?? $end;
    }
}
