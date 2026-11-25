<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Event\CopyEvent;
use App\Application\Event\EventCatalog;
use App\Application\Event\EventPresenter;
use App\Application\Event\RecordAttendance;
use App\Application\Event\SaveEvent;
use App\Application\ZurichClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EventController extends ApiController
{
    #[Route('/api/v1/events', name: 'api_events', methods: ['GET'])]
    public function index(EventCatalog $catalog, EventPresenter $presenter, ZurichClock $clock): Response
    {
        $user = $this->member();
        $today = $clock->today();
        $events = array_map(
            fn ($event): array => $presenter->detail($event, $user),
            $catalog->visibleTo($user),
        );

        return $this->jsonData(['events' => $events, 'today' => $today->format('Y-m-d')]);
    }

    #[Route('/api/v1/events/{id}', name: 'api_event', methods: ['GET'])]
    public function show(string $id, EventCatalog $catalog, EventPresenter $presenter): Response
    {
        $user = $this->member();

        return $this->jsonData($presenter->detail($catalog->visible($id, $user), $user));
    }

    #[Route('/api/v1/events', name: 'api_event_create', methods: ['POST'])]
    public function create(Request $request, SaveEvent $save, EventPresenter $presenter): Response
    {
        $user = $this->member();
        $event = $save->create($user, $this->payload($request));

        return $this->jsonData($presenter->detail($event, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/events/{id}/copy', name: 'api_event_copy', methods: ['POST'])]
    public function copy(string $id, CopyEvent $copy, EventPresenter $presenter): Response
    {
        $user = $this->member();
        $event = $copy($user, $id);

        return $this->jsonData($presenter->detail($event, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/events/{id}', name: 'api_event_update', methods: ['PUT'])]
    public function update(string $id, Request $request, SaveEvent $save, EventPresenter $presenter): Response
    {
        $user = $this->member();
        $event = $save->update($user, $id, $this->payload($request));

        return $this->jsonData($presenter->detail($event, $user));
    }

    #[Route('/api/v1/event-days/{id}/responses', name: 'api_event_response', methods: ['POST'])]
    public function respond(string $id, Request $request, RecordAttendance $record, EventCatalog $catalog, EventPresenter $presenter): Response
    {
        $user = $this->member();
        $payload = $this->payload($request);
        $record($user, $id, (string) ($payload['answer'] ?? ''), isset($payload['comment']) ? (string) $payload['comment'] : null);
        $day = $catalog->visibleTo($user);
        foreach ($day as $event) {
            if ($event->day($id) !== null) {
                return $this->jsonData($presenter->detail($event, $user));
            }
        }

        return $this->jsonData(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Der Inhalt ist kein gültiges JSON.');
        }

        return $payload;
    }
}
