<?php

declare(strict_types=1);

namespace App\Application\Helfer;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventHelferLink;
use App\Domain\Event\EventHelferLinkRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ShowHelfer
{
    public function __construct(
        private HelferCredentials $credentials,
        private HelferClient $client,
        private EventCatalog $events,
        private EventHelferLinkRepository $links,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function mine(MemberUser $member): array
    {
        try {
            [$base, $token, $email] = $this->connection($member);
        } catch (HelferNotConfigured $exception) {
            return $this->emptyMine($exception->missing, $exception->getMessage());
        } catch (HelferBaseInvalid $exception) {
            return $this->emptyMine(['HELFER_API_BASE'], $exception->getMessage());
        }
        if ($email === '') {
            return $this->emptyMine([], 'Die E-Mail-Adresse fehlt.', true);
        }

        $rows = [];
        $notice = null;
        foreach (['zugeteilt', 'gemeldet'] as $status) {
            try {
                $payload = $this->client->myAssignments($base, $token, $email, $status);
            } catch (HelferNotFound|HelferPersonUnknown) {
                continue;
            } catch (HelferRejected|UpstreamUnavailable $exception) {
                $notice ??= $exception->getMessage();
                continue;
            }
            foreach ($this->personalRows($payload) as $row) {
                $rows[] = $row;
            }
        }
        if ($rows !== []) {
            $notice = null;
        }

        return [
            'configured' => true,
            'missing' => [],
            'notice' => $notice,
            'assignedCount' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'zugeteilt')),
            'shifts' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    public function catalogue(MemberUser $member): array
    {
        $this->assertOrganizer($member);
        try {
            [$base, $token] = $this->connection($member);
            $plans = [];
            foreach (HelferMapper::collection($this->client->events($base, $token)) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $plan = HelferMapper::plan($row);
                if (!HelferMapper::isUuid($plan['id'])) {
                    continue;
                }
                $plans[] = $plan;
            }
        } catch (HelferNotConfigured $exception) {
            return ['configured' => false, 'missing' => $exception->missing, 'notice' => $exception->getMessage(), 'events' => []];
        } catch (HelferBaseInvalid $exception) {
            return ['configured' => false, 'missing' => ['HELFER_API_BASE'], 'notice' => $exception->getMessage(), 'events' => []];
        } catch (HelferRejected|UpstreamUnavailable $exception) {
            return ['configured' => true, 'missing' => [], 'notice' => $exception->getMessage(), 'events' => []];
        }

        return ['configured' => true, 'missing' => [], 'notice' => null, 'events' => $plans];
    }

    /** @return array<string, mixed> */
    public function forEvent(MemberUser $member, string $eventId): array
    {
        $event = $this->events->visible($eventId, $member);
        $canEdit = $member->isOrganizer();
        $link = $this->links->forEvent($event->id());
        try {
            [$base, $token, $email] = $this->connection($member);
            $reference = HelferMapper::reference($link?->helferEventId(), $event->id());
            $plan = $this->remotePlan($base, $token, $reference, $link);
            $shifts = $this->withPeople($base, $token, $this->shiftRows($this->client->shifts($base, $token, $reference)));
            $mine = $this->own($base, $token, $email, $reference);
        } catch (HelferNotConfigured $exception) {
            return $this->emptyEvent($exception->missing, $exception->getMessage(), $canEdit, false, false, $link);
        } catch (HelferBaseInvalid $exception) {
            return $this->emptyEvent(['HELFER_API_BASE'], $exception->getMessage(), $canEdit, false, false, $link);
        } catch (HelferNotFound) {
            return $this->emptyEvent([], null, $canEdit, true, $link instanceof EventHelferLink, $link);
        } catch (HelferRejected|HelferPersonUnknown|UpstreamUnavailable $exception) {
            return $this->emptyEvent([], $exception->getMessage(), $canEdit, true, $link instanceof EventHelferLink, $link);
        } catch (\InvalidArgumentException) {
            throw new InvalidRequest(['id' => 'Die Veranstaltungskennung ist ungültig.']);
        }

        return $this->view($canEdit, true, $mine['assigned'], $mine['url'], $plan['title'], $plan['url'] ?? $link?->url(), $shifts, null);
    }

    /** @return array<string, mixed> */
    public function publish(MemberUser $member, string $eventId): array
    {
        $this->assertOrganizer($member);
        $event = $this->events->visible($eventId, $member);
        try {
            [$base, $token] = $this->connection($member);
            $created = $this->client->publishEvent($base, $token, $this->payload($event));
            $plan = is_array($created) ? HelferMapper::plan($created) : ['id' => '', 'title' => $event->title(), 'date' => null, 'url' => null];
            if (!HelferMapper::isUuid($plan['id'])) {
                $loaded = $this->client->event($base, $token, HelferMapper::externalId($event->id()));
                if (is_array($loaded)) {
                    $plan = HelferMapper::plan($loaded);
                }
            }
            if (HelferMapper::isUuid($plan['id'])) {
                $this->remember($event, $plan['id'], $plan['title'], $plan['url'], $member, 'helfer_created');
            }
        } catch (HelferNotConfigured|HelferBaseInvalid|HelferRejected|HelferNotFound|UpstreamUnavailable $exception) {
            throw $exception;
        }

        return $this->forEvent($member, $event->id());
    }

    /** @return array<string, mixed> */
    public function link(MemberUser $member, string $eventId, string $helferEventId): array
    {
        $this->assertOrganizer($member);
        if (!HelferMapper::isUuid($helferEventId)) {
            throw new InvalidRequest(['helferEventId' => 'Die Helferplanung ist ungültig.']);
        }
        $event = $this->events->visible($eventId, $member);
        try {
            [$base, $token] = $this->connection($member);
            $loaded = $this->client->event($base, $token, $helferEventId);
        } catch (HelferNotConfigured|HelferBaseInvalid|HelferRejected|HelferNotFound|UpstreamUnavailable $exception) {
            throw $exception;
        }
        if (!is_array($loaded)) {
            throw new UpstreamUnavailable('Die Helferplanung hat nicht mit einem Anlass geantwortet.');
        }
        $plan = HelferMapper::plan($loaded);
        $this->remember($event, $helferEventId, $plan['title'], $plan['url'], $member, 'helfer_linked');

        return $this->forEvent($member, $event->id());
    }

    public function blocksDecline(MemberUser $member, string $eventId): bool
    {
        try {
            [$base, $token, $email] = $this->connection($member);
            $link = $this->links->forEvent($eventId);
            $mine = $this->own($base, $token, $email, HelferMapper::reference($link?->helferEventId(), $eventId));
        } catch (\Throwable) {
            return false;
        }

        return $mine['assigned'] && $mine['binding'];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function connection(MemberUser $member): array
    {
        $resolved = $this->credentials->resolve($this->credentials->fromProcess());

        return [$resolved['base'], $resolved['token'], $member->email() ?? ''];
    }

    /** @return list<array<string, mixed>> */
    private function shiftRows(mixed $payload): array
    {
        $rows = [];
        foreach (HelferMapper::collection($payload) as $row) {
            if (is_array($row)) {
                $rows[] = HelferMapper::shift($row);
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $shifts
     * @return list<array<string, mixed>>
     */
    private function withPeople(string $base, string $token, array $shifts): array
    {
        foreach ($shifts as $index => $shift) {
            $id = is_string($shift['id'] ?? null) ? $shift['id'] : '';
            $shifts[$index]['people'] = [];
            if (!HelferMapper::isUuid($id)) {
                continue;
            }
            try {
                $payload = $this->client->assignments($base, $token, $id);
            } catch (HelferNotFound|HelferRejected|HelferPersonUnknown|UpstreamUnavailable|\InvalidArgumentException) {
                continue;
            }
            $people = [];
            foreach (HelferMapper::collection($payload) as $row) {
                if (is_array($row)) {
                    $people[] = HelferMapper::shiftPerson($row);
                }
            }
            $shifts[$index]['people'] = $people;
        }

        return $shifts;
    }

    /** @return list<array<string, mixed>> */
    private function personalRows(mixed $payload): array
    {
        if (is_array($payload) && !array_is_list($payload) && array_key_exists('shifts', $payload)) {
            return HelferMapper::myAssignment($payload)['shifts'];
        }

        $rows = [];
        foreach (HelferMapper::collection($payload) as $row) {
            if (is_array($row)) {
                $rows[] = HelferMapper::assignment($row);
            }
        }

        return $rows;
    }

    /** @return array{assigned: bool, binding: bool, url: string|null} */
    private function own(string $base, string $token, string $email, string $reference): array
    {
        if ($email === '') {
            return ['assigned' => false, 'binding' => false, 'url' => null];
        }
        try {
            $payload = $this->client->myAssignment($base, $token, $email, $reference);
        } catch (HelferNotFound|HelferPersonUnknown) {
            return ['assigned' => false, 'binding' => false, 'url' => null];
        }
        if (!is_array($payload)) {
            return ['assigned' => false, 'binding' => false, 'url' => null];
        }
        $mine = HelferMapper::myAssignment($payload);

        return ['assigned' => $mine['assigned'], 'binding' => $mine['binding'], 'url' => $mine['url']];
    }

    /** @return array<string, scalar|null> */
    private function payload(Event $event): array
    {
        $day = $event->days()[0] ?? null;
        $fields = [
            'eventId' => $event->id(),
            'title' => $event->title(),
            'date' => $day?->occursOn()->format('Y-m-d') ?? (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ];
        $start = $day?->startsAt()?->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('H:i');
        $end = $day?->endsAt()?->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('H:i');
        if ($start !== null) {
            $fields['startTime'] = $start;
        }
        if ($end !== null) {
            $fields['endTime'] = $end;
        }
        $location = $day?->location() ?? $event->location();
        if (is_string($location) && trim($location) !== '') {
            $fields['location'] = trim($location);
        }

        return $fields;
    }

    /** @param list<string> $missing @return array<string, mixed> */
    private function emptyMine(array $missing, string $notice, bool $configured = false): array
    {
        return [
            'configured' => $configured,
            'missing' => $missing,
            'notice' => $notice,
            'assignedCount' => 0,
            'shifts' => [],
        ];
    }

    /** @param list<string> $missing @return array<string, mixed> */
    private function emptyEvent(array $missing, ?string $notice, bool $canEdit, bool $configured, bool $present, ?EventHelferLink $link): array
    {
        return $this->view($canEdit, $present, false, null, $link?->title(), $link?->url(), [], $notice, $configured, $missing);
    }

    /**
     * @param list<array<string, mixed>> $shifts
     * @param list<string> $missing
     * @return array<string, mixed>
     */
    private function view(bool $canEdit, bool $present, bool $assigned, ?string $url, ?string $planTitle, ?string $planUrl, array $shifts, ?string $notice, bool $configured = true, array $missing = []): array
    {
        return [
            'configured' => $configured,
            'missing' => $missing,
            'notice' => $notice,
            'present' => $present,
            'canEdit' => $canEdit,
            'assigned' => $assigned,
            'url' => $url,
            'planTitle' => $planTitle,
            'planUrl' => HelferMapper::httpsUrl($planUrl),
            'shifts' => $shifts,
        ];
    }

    /** @return array{title: string|null, url: string|null} */
    private function remotePlan(string $base, string $token, string $reference, ?EventHelferLink $link): array
    {
        try {
            $loaded = $this->client->event($base, $token, $reference);
        } catch (HelferNotFound|HelferRejected|UpstreamUnavailable) {
            return ['title' => $link?->title(), 'url' => $link?->url()];
        }
        if (!is_array($loaded)) {
            return ['title' => $link?->title(), 'url' => $link?->url()];
        }
        $plan = HelferMapper::plan($loaded);
        if ($link instanceof EventHelferLink && HelferMapper::isUuid($plan['id'])) {
            $link->retarget($plan['id'], $plan['title'], $plan['url']);
            $this->entityManager->flush();
        }

        return ['title' => $plan['title'], 'url' => $plan['url']];
    }

    private function remember(Event $event, string $helferEventId, ?string $title, ?string $url, MemberUser $member, string $action): void
    {
        $link = $this->links->forEvent($event->id());
        $before = null;
        $changed = true;
        if ($link instanceof EventHelferLink) {
            $changed = $link->helferEventId() !== $helferEventId || $link->title() !== $title;
            $before = ['plan' => $link->title()];
            $link->retarget($helferEventId, $title, $url);
        } else {
            $this->links->add(new EventHelferLink($event, $helferEventId, $title, $url, $this->clock->now()));
        }
        if ($changed) {
            $this->audit->record(
                $member->getUserIdentifier(),
                'event',
                $event->id(),
                $action,
                $before,
                ['plan' => $title],
                $this->clock->now(),
                $member->displayName(),
            );
        }
        $this->entityManager->flush();
    }

    private function assertOrganizer(MemberUser $member): void
    {
        if (!$member->isOrganizer()) {
            throw new AccessDeniedException('Nur die Organisation verknüpft eine Helferplanung.');
        }
    }
}
