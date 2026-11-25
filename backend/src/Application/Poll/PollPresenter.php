<?php

declare(strict_types=1);

namespace App\Application\Poll;

use App\Domain\Poll\Poll;
use App\Domain\Poll\PollOption;
use App\Domain\Poll\PollVote;
use App\Security\MemberUser;

final class PollPresenter
{
    private const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    private const MONTHS = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    /** @return array<string, mixed> */
    public function detail(Poll $poll, MemberUser $user): array
    {
        $sub = $user->getUserIdentifier();
        $state = $poll->memberState($sub);
        $zone = new \DateTimeZone('Europe/Zurich');
        $options = array_map(fn (PollOption $option): array => $this->option($poll, $option, $sub), $poll->options());

        return [
            'id' => $poll->id(),
            'title' => $poll->title(),
            'kind' => $poll->kind()->value,
            'kindLabel' => $poll->kind()->label(),
            'status' => $poll->status()->value,
            'statusLabel' => $poll->status()->label(),
            'description' => $poll->description(),
            'location' => $poll->location(),
            'clubs' => array_map(static fn ($club) => $club->value, $poll->clubs()),
            'clubLabels' => array_map(static fn ($club) => $club->label(), $poll->clubs()),
            'deadline' => $poll->deadline()?->setTimezone($zone)->format('Y-m-d'),
            'deadlinePassed' => $poll->deadlinePassed(new \DateTimeImmutable('now', $zone)),
            'acceptsAnswers' => $poll->acceptsAnswers(new \DateTimeImmutable('now', $zone)),
            'eventId' => $poll->createdEventId(),
            'myState' => $state,
            'myStateLabel' => $this->stateLabel($state),
            'comment' => $poll->answerFor($sub)?->comment(),
            'options' => $options,
            'days' => $this->days($options),
        ];
    }

    /** @return array<string, mixed> */
    public function card(Poll $poll, MemberUser $user): array
    {
        $state = $poll->memberState($user->getUserIdentifier());
        $zone = new \DateTimeZone('Europe/Zurich');

        return [
            'id' => $poll->id(),
            'title' => $poll->title(),
            'kind' => $poll->kind()->value,
            'kindLabel' => $poll->kind()->label(),
            'status' => $poll->status()->value,
            'statusLabel' => $poll->status()->label(),
            'deadline' => $poll->deadline()?->setTimezone($zone)->format('Y-m-d'),
            'optionCount' => count($poll->options()),
            'myState' => $state,
            'myStateLabel' => $this->stateLabel($state),
            'eventId' => $poll->createdEventId(),
            'acceptsAnswers' => $poll->acceptsAnswers(new \DateTimeImmutable('now', $zone)),
        ];
    }

    /** @return array<string, mixed> */
    public function spotlight(Poll $poll, MemberUser $user): array
    {
        $state = $poll->memberState($user->getUserIdentifier());
        $prompt = match ($state) {
            'supplement' => 'Ergänzung erforderlich.',
            'complete' => 'Deine Antwort ist gespeichert.',
            default => $poll->description() ?? 'Bitte gib deine Verfügbarkeit an.',
        };

        return [
            'id' => $poll->id(),
            'title' => $poll->title(),
            'prompt' => $prompt,
            'state' => $state,
        ];
    }

    /** @return array<string, mixed> */
    private function option(Poll $poll, PollOption $option, string $sub): array
    {
        $vote = $option->currentVote($sub);
        $saved = $poll->answerFor($sub) !== null;
        $yes = [];
        $no = 0;
        foreach ($option->currentVotes() as $current) {
            if ($current->answer()->value === 'yes') {
                $yes[] = $poll->answerFor($current->auth0Sub())?->respondentName() ?? 'Mitglied';
            } else {
                ++$no;
            }
        }

        return [
            'id' => $option->id(),
            'date' => $option->occursOn()->format('Y-m-d'),
            'dateLabel' => $this->longDate($option->occursOn()),
            'daypart' => $option->daypart()?->value,
            'daypartLabel' => $option->daypart()?->label(),
            'startsAt' => $option->startsAt()?->format('H:i'),
            'endsAt' => $option->endsAt()?->format('H:i'),
            'answer' => $vote?->answer()->value,
            'needsSupplement' => $saved && !$vote instanceof PollVote,
            'yesCount' => count($yes),
            'noCount' => $no,
            'yesNames' => $yes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return list<array<string, mixed>>
     */
    private function days(array $options): array
    {
        $days = [];
        foreach ($options as $option) {
            $date = (string) $option['date'];
            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'label' => $option['dateLabel'],
                    'options' => [],
                ];
            }
            $days[$date]['options'][] = $option;
        }

        return array_values($days);
    }

    private function longDate(\DateTimeImmutable $date): string
    {
        $weekday = self::WEEKDAYS[(int) $date->format('w')];
        $month = self::MONTHS[(int) $date->format('n')];

        return $weekday.', '.$date->format('j').'. '.$month;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'complete' => 'Beantwortet',
            'supplement' => 'Ergänzung erforderlich',
            default => 'Noch nicht beantwortet',
        };
    }
}
