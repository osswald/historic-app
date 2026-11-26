<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Event\EventCatalog;
use App\Application\Event\EventPresenter;
use App\Application\Poll\PollCatalog;
use App\Application\Poll\PollPresenter;
use App\Application\Tasks\ShowTasks;
use App\Application\ZurichClock;
use App\Security\MemberUser;

final class BuildDashboard
{
    public function __construct(
        private EventCatalog $catalog,
        private EventPresenter $presenter,
        private PollCatalog $polls,
        private PollPresenter $pollPresenter,
        private ZurichClock $clock,
        private ShowTasks $tasks,
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
                if ($day->responseForMember($user->getUserIdentifier(), $user->spondId()) === null) {
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

        $now = $this->clock->now();
        $answerable = [];
        $pending = 0;
        $featured = null;
        foreach ($this->polls->visibleTo($user) as $poll) {
            if (!$poll->acceptsAnswers($now)) {
                continue;
            }
            $answerable[] = $poll;
            if ($poll->memberState($user->getUserIdentifier()) !== 'complete') {
                ++$pending;
                $featured ??= $poll;
            }
        }
        $featured ??= $answerable[0] ?? null;
        $ownTasks = $this->tasks->tasks($user, null);
        $taskRows = is_array($ownTasks['tasks'] ?? null) ? $ownTasks['tasks'] : [];

        return [
            'counts' => [
                'openResponses' => $open,
                'polls' => $pending,
                'shifts' => 0,
                'tasks' => ($ownTasks['configured'] ?? false) === true && ($ownTasks['notice'] ?? null) === null ? count($taskRows) : null,
            ],
            'upcoming' => $cards,
            'nextSevenDays' => $week,
            'tasks' => $taskRows,
            'tasksNotice' => $ownTasks['notice'] ?? null,
            'shifts' => [],
            'openPoll' => $featured instanceof \App\Domain\Poll\Poll ? $this->pollPresenter->spotlight($featured, $user) : null,
        ];
    }
}
