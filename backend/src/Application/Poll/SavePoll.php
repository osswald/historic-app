<?php

declare(strict_types=1);

namespace App\Application\Poll;

use App\Application\InvalidRequest;
use App\Application\Membership\ClubScope;
use App\Application\ZurichClock;
use App\Domain\Event\Club;
use App\Domain\Poll\Daypart;
use App\Domain\Poll\Poll;
use App\Domain\Poll\PollKind;
use App\Domain\Poll\PollOption;
use App\Domain\Poll\PollRepository;
use App\Domain\Poll\PollStatus;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SavePoll
{
    public function __construct(
        private PollRepository $polls,
        private PollCatalog $catalog,
        private ClubScope $clubs,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(MemberUser $actor, array $payload): Poll
    {
        $this->assertOrganizer($actor);
        $input = $this->normalize($payload, null);
        $this->clubs->assertAssignable($actor, $input['clubs']);
        $now = $this->clock->now();
        $poll = new Poll(
            $input['title'],
            $input['kind'],
            $input['status'],
            $input['description'],
            $input['location'],
            $input['clubs'],
            $input['deadline'],
            $now,
        );
        $this->syncOptions($poll, $input['options']);
        $this->polls->add($poll);
        $this->audit->record($actor->getUserIdentifier(), 'poll', $poll->id(), 'create', null, $poll->toAudit(), $now, $actor->displayName());
        $this->entityManager->flush();

        return $poll;
    }

    /** @param array<string, mixed> $payload */
    public function update(MemberUser $actor, string $id, array $payload): Poll
    {
        $this->assertOrganizer($actor);
        $poll = $this->catalog->visible($id, $actor);
        $input = $this->normalize($payload, $poll);
        $this->clubs->assertAssignable($actor, $input['clubs']);
        $before = $poll->toAudit();
        $now = $this->clock->now();
        $poll->change(
            $input['title'],
            $input['status'],
            $input['description'],
            $input['location'],
            $input['clubs'],
            $input['deadline'],
            $now,
        );
        $this->syncOptions($poll, $input['options']);
        $this->audit->record($actor->getUserIdentifier(), 'poll', $poll->id(), 'update', $before, $poll->toAudit(), $now, $actor->displayName());
        $this->entityManager->flush();

        return $poll;
    }

    private function assertOrganizer(MemberUser $actor): void
    {
        if (!$actor->isOrganizer()) {
            throw new AccessDeniedException('Umfragen bearbeiten darf nur ein Organisator.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     title: string,
     *     kind: PollKind,
     *     status: PollStatus,
     *     description: ?string,
     *     location: ?string,
     *     clubs: list<string>,
     *     deadline: ?\DateTimeImmutable,
     *     options: list<array{id: ?string, date: \DateTimeImmutable, daypart: ?Daypart, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable}>
     * }
     */
    private function normalize(array $payload, ?Poll $existing): array
    {
        $errors = [];
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Bitte einen Titel mit höchstens 200 Zeichen angeben.';
        }

        $kind = PollKind::tryFrom((string) ($payload['kind'] ?? ''));
        if (!$kind instanceof PollKind) {
            $errors['kind'] = 'Bitte Terminfindung oder Depottag wählen.';
            $kind = PollKind::Normal;
        }
        if ($existing instanceof Poll && $existing->kind() !== $kind) {
            $errors['kind'] = 'Die Art der Umfrage lässt sich nicht ändern.';
            $kind = $existing->kind();
        }

        $status = PollStatus::tryFrom((string) ($payload['status'] ?? ''));
        if (!$status instanceof PollStatus) {
            $errors['status'] = 'Bitte Offen oder Geschlossen wählen.';
            $status = PollStatus::Open;
        }

        $clubs = [];
        foreach ((array) ($payload['clubs'] ?? []) as $value) {
            $club = Club::tryFrom((string) $value);
            if ($club instanceof Club) {
                $clubs[] = $club->value;
            }
        }
        $clubs = array_values(array_unique($clubs));
        if ($clubs === []) {
            $errors['clubs'] = 'Bitte mindestens einen Verein auswählen.';
        }

        $deadline = null;
        $deadlineRaw = trim((string) ($payload['deadline'] ?? ''));
        if ($deadlineRaw !== '') {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $deadlineRaw, new \DateTimeZone('Europe/Zurich'));
            if (!$day instanceof \DateTimeImmutable) {
                $errors['deadline'] = 'Bitte ein gültiges Datum für die Antwortfrist angeben.';
            } else {
                $deadline = $day->setTime(23, 59, 59);
            }
        }

        $options = [];
        $seen = [];
        foreach ((array) ($payload['options'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $dateRaw = trim((string) ($row['date'] ?? ''));
            $startsRaw = trim((string) ($row['startsAt'] ?? ''));
            $endsRaw = trim((string) ($row['endsAt'] ?? ''));
            $daypartRaw = trim((string) ($row['daypart'] ?? ''));
            if ($dateRaw === '' && $startsRaw === '' && $endsRaw === '' && $daypartRaw === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if (!$date instanceof \DateTimeImmutable) {
                $errors['options.'.$index.'.date'] = 'Bitte ein gültiges Datum angeben.';
                continue;
            }
            $daypart = null;
            $startsAt = $this->time($startsRaw);
            $endsAt = $this->time($endsRaw);
            if ($kind === PollKind::Depot) {
                $daypart = Daypart::tryFrom($daypartRaw);
                if (!$daypart instanceof Daypart) {
                    $errors['options.'.$index.'.daypart'] = 'Bitte Vormittag, Nachmittag oder Abend wählen.';
                    continue;
                }
                if ($startsRaw !== '' || $endsRaw !== '') {
                    $errors['options.'.$index.'.startsAt'] = 'Ein Depottag hat keine Uhrzeit.';
                }
                $startsAt = null;
                $endsAt = null;
            } elseif ($daypartRaw !== '') {
                $errors['options.'.$index.'.daypart'] = 'Tageszeiten gibt es nur bei Depottagen.';
            } else {
                if ($startsRaw !== '' && !$startsAt instanceof \DateTimeImmutable) {
                    $errors['options.'.$index.'.startsAt'] = 'Bitte eine gültige Uhrzeit angeben.';
                }
                if ($endsRaw !== '' && !$endsAt instanceof \DateTimeImmutable) {
                    $errors['options.'.$index.'.endsAt'] = 'Bitte eine gültige Uhrzeit angeben.';
                }
                if ($startsAt instanceof \DateTimeImmutable && $endsAt instanceof \DateTimeImmutable && $endsAt->format('H:i') < $startsAt->format('H:i')) {
                    $errors['options.'.$index.'.endsAt'] = 'Das Ende liegt vor dem Beginn.';
                }
            }

            $key = $date->format('Y-m-d').'|'.($daypart?->value ?? '').'|'.($startsAt?->format('H:i') ?? '').'|'.($endsAt?->format('H:i') ?? '');
            if (isset($seen[$key])) {
                $errors['options.'.$index.'.date'] = 'Diese Option ist doppelt.';
                continue;
            }
            $seen[$key] = true;
            $id = trim((string) ($row['id'] ?? ''));
            $options[] = [
                'id' => $id !== '' ? $id : null,
                'date' => $date,
                'daypart' => $daypart,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ];
        }
        if ($options === []) {
            $errors['options'] = 'Bitte mindestens eine Option angeben.';
        }

        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return [
            'title' => $title,
            'kind' => $kind,
            'status' => $status,
            'description' => $this->nullableText($payload['description'] ?? null, 8000),
            'location' => $this->nullableText($payload['location'] ?? null, 200),
            'clubs' => $clubs,
            'deadline' => $deadline,
            'options' => $options,
        ];
    }

    /** @param list<array{id: ?string, date: \DateTimeImmutable, daypart: ?Daypart, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable}> $options */
    private function syncOptions(Poll $poll, array $options): void
    {
        $kept = [];
        $errors = [];
        foreach ($options as $index => $option) {
            $existing = is_string($option['id']) ? $poll->option($option['id']) : null;
            if ($existing instanceof PollOption && $existing->isAdopted()) {
                $changed = $existing->occursOn()->format('Y-m-d') !== $option['date']->format('Y-m-d')
                    || $existing->daypart() !== $option['daypart']
                    || $existing->startsAt()?->format('H:i') !== $option['startsAt']?->format('H:i')
                    || $existing->endsAt()?->format('H:i') !== $option['endsAt']?->format('H:i');
                if ($changed) {
                    $errors['options.'.$index] = 'Ein übernommener Vorschlag ist gesperrt.';
                }
                $existing->revise($existing->occursOn(), $existing->daypart(), $existing->startsAt(), $existing->endsAt(), $index);
                $kept[] = $existing->id();
                continue;
            }
            if ($existing instanceof PollOption) {
                $existing->revise($option['date'], $option['daypart'], $option['startsAt'], $option['endsAt'], $index);
                $kept[] = $existing->id();
                continue;
            }
            $created = $poll->addOption($option['date'], $option['daypart'], $option['startsAt'], $option['endsAt'], $index);
            $kept[] = $created->id();
        }
        foreach ($poll->options() as $option) {
            if ($option->isAdopted() && !in_array($option->id(), $kept, true)) {
                $errors['options'] = 'Ein übernommener Vorschlag kann nicht entfernt werden.';
            }
        }
        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }
        $poll->retainOptions($kept);
    }

    private function time(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);

        return $time instanceof \DateTimeImmutable ? $time : null;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }
}
