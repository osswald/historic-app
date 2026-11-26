<?php

declare(strict_types=1);

namespace App\Application\Tasks;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventTaskListLink;
use App\Domain\Event\EventTaskListRepository;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class EventTaskLists
{
    public function __construct(
        private EventCatalog $events,
        private EventTaskListRepository $links,
        private ShowTasks $tasks,
        private TasksClient $client,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forEvent(MemberUser $member, string $eventId): array
    {
        $event = $this->events->visible($eventId, $member);
        $canEdit = $member->isOrganizer();
        try {
            [$base, $token, $sub] = $this->tasks->connection($member);
            $lists = $this->resolve($base, $token, $sub, $event->id());
        } catch (TasksNotConfigured $exception) {
            return $this->storedView($event->id(), false, $exception->missing, $exception->getMessage(), $canEdit);
        } catch (TasksBaseInvalid $exception) {
            return $this->storedView($event->id(), false, ['TASKS_API_BASE'], $exception->getMessage(), $canEdit);
        } catch (TasksRejected|TasksPersonUnknown|UpstreamUnavailable $exception) {
            return $this->storedView($event->id(), true, [], $exception->getMessage(), $canEdit);
        }

        return [
            'configured' => true,
            'missing' => [],
            'notice' => null,
            'canEdit' => $canEdit,
            'lists' => $lists,
        ];
    }

    /**
     * Reads each linked list from the tasks API.
     * A list the tasks API reports as missing stays stored and is left out of the result.
     * Any other failure keeps the last stored name. The association row is never removed here.
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(string $base, string $token, string $actingUser, string $eventId): array
    {
        $items = [];
        $changed = false;
        foreach ($this->links->forEvent($eventId) as $link) {
            try {
                $data = $this->client->taskList($base, $token, $actingUser, $link->taskListId());
            } catch (TasksNotFound) {
                continue;
            } catch (InvalidRequest|TasksRejected|TasksPersonUnknown|UpstreamUnavailable) {
                $items[] = $this->stored($link, false);
                continue;
            }
            if (!is_array($data) || array_is_list($data)) {
                $items[] = $this->stored($link, false);
                continue;
            }
            $mapped = TasksMapper::taskList($data);
            $name = $mapped['name'] ?? null;
            if (is_string($name) && $name !== $link->name()) {
                $link->rememberName($name);
                $changed = true;
            }
            $items[] = [
                'id' => $link->taskListId(),
                'name' => is_string($name) && $name !== '' ? $name : $link->name(),
                'category' => $mapped['category'] ?? null,
                'orgGroup' => $mapped['orgGroup'] ?? null,
                'progress' => $mapped['progress'],
                'addressable' => $mapped['addressable'] === true,
                'unavailable' => false,
            ];
        }
        if ($changed) {
            $this->entityManager->flush();
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{id: string, name: string|null}
     */
    public function create(MemberUser $member, string $eventId, array $fields): array
    {
        if (!$member->isOrganizer()) {
            throw new AccessDeniedException('Veranstaltungen bearbeiten darf nur ein Organisator.');
        }
        $event = $this->events->visible($eventId, $member);
        $created = $this->tasks->create($member, $fields);
        $id = $created['id'] ?? null;
        if (!is_string($id) || !TasksMapper::safeId($id)) {
            throw new UpstreamUnavailable('Die Aufgaben-App hat die Liste nicht bestätigt.');
        }
        $submitted = $fields['name'] ?? null;
        $name = is_string($submitted) ? $this->limit(trim($submitted)) : null;
        if ($name === '') {
            $name = null;
        }
        $this->attach($event, $id, $name);

        return ['id' => $id, 'name' => $name];
    }

    private function attach(Event $event, string $taskListId, ?string $name): void
    {
        $existing = $this->links->findForEvent($event->id(), $taskListId);
        if ($existing instanceof EventTaskListLink) {
            if ($name !== null && $name !== $existing->name()) {
                $existing->rememberName($name);
            }
        } else {
            $this->links->add(new EventTaskListLink($event, $taskListId, $name, $this->clock->now()));
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $missing
     * @return array<string, mixed>
     */
    private function storedView(string $eventId, bool $configured, array $missing, string $notice, bool $canEdit): array
    {
        return [
            'configured' => $configured,
            'missing' => $missing,
            'notice' => $notice,
            'canEdit' => $canEdit,
            'lists' => array_map(
                fn (EventTaskListLink $link): array => $this->stored($link, false),
                $this->links->forEvent($eventId),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function stored(EventTaskListLink $link, bool $unavailable): array
    {
        return [
            'id' => $link->taskListId(),
            'name' => $link->name(),
            'category' => null,
            'orgGroup' => null,
            'progress' => null,
            'addressable' => !$unavailable && TasksMapper::safeId($link->taskListId()),
            'unavailable' => $unavailable,
        ];
    }

    private function limit(string $value): string
    {
        return strlen($value) > 500 ? substr($value, 0, 500) : $value;
    }
}
