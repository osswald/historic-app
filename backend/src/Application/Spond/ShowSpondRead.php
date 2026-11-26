<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Spond\SpondEvent;
use App\Domain\Spond\SpondRead;
use App\Domain\Spond\SpondReadRepository;
use App\Domain\Spond\SpondReply;
use App\Security\MemberUser;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ShowSpondRead
{
    public function __construct(
        private SpondReadRepository $reads,
    ) {
    }

    /** @return array{read: array<string, mixed>|null} */
    public function __invoke(MemberUser $user): array
    {
        if (!$user->isOrganizer()) {
            throw new AccessDeniedException('Den Spond-Lesestand sehen Organisatoren und Administratoren.');
        }

        return ['read' => $this->detail($this->reads->latest())];
    }

    /** @return array<string, mixed>|null */
    private function detail(?SpondRead $read): ?array
    {
        if (!$read instanceof SpondRead) {
            return null;
        }

        $events = $read->events();
        usort($events, static function (SpondEvent $left, SpondEvent $right): int {
            $byDate = $right->eventOn() <=> $left->eventOn();
            if ($byDate !== 0) {
                return $byDate;
            }

            return strcmp($left->heading(), $right->heading());
        });

        $rows = [];
        foreach ($events as $event) {
            $rows[] = $this->eventDetail($event);
        }

        $earliest = $read->earliestOn();
        $latest = $read->latestOn();

        return [
            'groupName' => $read->groupName(),
            'memberCount' => count($read->people()),
            'eventCount' => count($rows),
            'earliestOn' => $earliest?->format('Y-m-d'),
            'latestOn' => $latest?->format('Y-m-d'),
            'accepted' => $read->replyCount(SpondReply::Accepted),
            'declined' => $read->replyCount(SpondReply::Declined),
            'unanswered' => $read->replyCount(SpondReply::Unanswered),
            'storedIdCount' => $read->storedIdCount(),
            'matchedCount' => $read->matchedCount(),
            'idsMatched' => true,
            'readAt' => $read->readAt()->format(\DateTimeInterface::ATOM),
            'events' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function eventDetail(SpondEvent $event): array
    {
        $comments = [];
        foreach ($event->answers() as $answer) {
            $comment = $answer->comment();
            if ($comment !== null) {
                $comments[] = $comment;
            }
        }

        $options = [];
        foreach ($event->options() as $option) {
            $options[] = [
                'name' => $option['name'],
                'description' => $option['description'],
                'accepted' => $option['accepted'],
                'declined' => $option['declined'],
                'unanswered' => $option['unanswered'],
            ];
        }

        return [
            'heading' => $event->heading(),
            'eventOn' => $event->eventOn()->format('Y-m-d'),
            'description' => $event->description(),
            'comment' => $event->comment(),
            'accepted' => $event->replyCount(SpondReply::Accepted),
            'declined' => $event->replyCount(SpondReply::Declined),
            'unanswered' => $event->replyCount(SpondReply::Unanswered),
            'answerComments' => $comments,
            'options' => $options,
        ];
    }
}
