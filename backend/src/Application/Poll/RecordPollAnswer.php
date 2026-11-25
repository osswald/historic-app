<?php

declare(strict_types=1);

namespace App\Application\Poll;

use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Event\Attendance;
use App\Domain\Poll\PollVote;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

final class RecordPollAnswer
{
    public function __construct(
        private PollCatalog $catalog,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function __invoke(MemberUser $actor, string $pollId, array $payload): void
    {
        $poll = $this->catalog->visible($pollId, $actor);
        $now = $this->clock->now();
        if ($poll->status()->value === 'closed') {
            throw new InvalidRequest(['status' => 'Diese Umfrage ist geschlossen.'], 'Diese Umfrage ist geschlossen.');
        }
        if ($poll->deadlinePassed($now)) {
            throw new InvalidRequest(['deadline' => 'Die Antwortfrist ist abgelaufen.'], 'Die Antwortfrist ist abgelaufen.');
        }

        $incoming = [];
        foreach ((array) ($payload['votes'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $optionId = trim((string) ($row['optionId'] ?? ''));
            $answer = Attendance::tryFrom((string) ($row['answer'] ?? ''));
            if ($optionId === '' || !$answer instanceof Attendance) {
                throw new InvalidRequest(['votes' => 'Bitte jede Option mit Ja oder Nein beantworten.']);
            }
            $incoming[$optionId] = $answer;
        }

        $options = $poll->options();
        if ($options === []) {
            throw new InvalidRequest(['options' => 'Diese Umfrage hat keine Optionen.']);
        }
        foreach ($options as $option) {
            if (!isset($incoming[$option->id()])) {
                throw new InvalidRequest(['votes' => 'Bitte jede Option mit Ja oder Nein beantworten.']);
            }
        }
        if (count($incoming) !== count($options)) {
            throw new InvalidRequest(['votes' => 'Bitte nur Optionen dieser Umfrage beantworten.']);
        }

        $comment = trim((string) ($payload['comment'] ?? ''));
        $comment = $comment === '' ? null : mb_substr($comment, 0, 2000);
        $before = $this->snapshot($poll, $actor->getUserIdentifier());
        $poll->recordAnswer($actor->getUserIdentifier(), $actor->displayName(), $comment, $now);
        foreach ($options as $option) {
            $option->cast($actor->getUserIdentifier(), $incoming[$option->id()], $now);
        }
        $after = $this->snapshot($poll, $actor->getUserIdentifier());
        if ($before === $after) {
            return;
        }

        $answer = $poll->answerFor($actor->getUserIdentifier());
        $this->audit->record(
            $actor->getUserIdentifier(),
            'poll_answer',
            $answer?->id() ?? $poll->id(),
            $before === null ? 'create' : 'update',
            $before,
            $after,
            $now,
        );
        $this->entityManager->flush();
    }

    /** @return array<string, mixed>|null */
    private function snapshot(\App\Domain\Poll\Poll $poll, string $sub): ?array
    {
        $answer = $poll->answerFor($sub);
        if ($answer === null) {
            return null;
        }
        $votes = [];
        foreach ($poll->options() as $option) {
            $vote = $option->voteFor($sub);
            if ($vote instanceof PollVote) {
                $votes[] = $vote->toAudit();
            }
        }

        return [
            'comment' => $answer->comment(),
            'votes' => $votes,
        ];
    }
}
