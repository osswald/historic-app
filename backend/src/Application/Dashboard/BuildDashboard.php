<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Event\EventCatalog;
use App\Application\Event\EventPresenter;
use App\Application\ZurichClock;
use App\Security\MemberUser;

final class BuildDashboard
{
    public function __construct(
        private EventCatalog $catalog,
        private EventPresenter $presenter,
        private ZurichClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(MemberUser $user): array
    {
        $today = $this->clock->today();
        $upcoming = $this->catalog->upcoming($user, $today);
        $open = 0;
        $cards = [];
        foreach ($upcoming as $event) {
            $needsAnswer = false;
            foreach ($event->days() as $day) {
                if ($day->occursOn()->format('Y-m-d') < $today->format('Y-m-d')) {
                    continue;
                }
                if ($day->responseFor($user->getUserIdentifier()) === null) {
                    $needsAnswer = true;
                }
            }
            if ($needsAnswer) {
                ++$open;
            }
            $cards[] = $this->presenter->card($event, $user, $today);
        }

        $eventDates = [];
        foreach ($upcoming as $event) {
            foreach ($event->days() as $day) {
                $eventDates[$day->occursOn()->format('Y-m-d')] = true;
            }
        }

        $week = [];
        $weekdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        for ($offset = 0; $offset < 7; ++$offset) {
            $date = $today->modify('+'.$offset.' days');
            $key = $date->format('Y-m-d');
            $week[] = [
                'date' => $key,
                'weekday' => $weekdays[(int) $date->format('w')],
                'day' => $date->format('j'),
                'hasEvent' => isset($eventDates[$key]),
            ];
        }

        return [
            'counts' => [
                'openResponses' => $open,
                'polls' => 0,
                'shifts' => 0,
                'tasks' => 0,
            ],
            'upcoming' => $cards,
            'nextSevenDays' => $week,
            'tasks' => [],
            'shifts' => [],
            'openPoll' => null,
        ];
    }
}
