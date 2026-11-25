<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Event\EventPresenter;
use App\Application\Poll\AdoptPoll;
use App\Application\Poll\PollCatalog;
use App\Application\Poll\PollPresenter;
use App\Application\Poll\RecordPollAnswer;
use App\Application\Poll\SavePoll;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PollController extends ApiController
{
    #[Route('/api/v1/polls', name: 'api_polls', methods: ['GET'])]
    public function index(PollCatalog $catalog, PollPresenter $presenter): Response
    {
        $user = $this->member();

        return $this->jsonData([
            'polls' => array_map(fn ($poll): array => $presenter->card($poll, $user), $catalog->visibleTo($user)),
        ]);
    }

    #[Route('/api/v1/polls/{id}', name: 'api_poll', methods: ['GET'])]
    public function show(string $id, PollCatalog $catalog, PollPresenter $presenter): Response
    {
        $user = $this->member();

        return $this->jsonData($presenter->detail($catalog->visible($id, $user), $user));
    }

    #[Route('/api/v1/polls', name: 'api_poll_create', methods: ['POST'])]
    public function create(Request $request, SavePoll $save, PollPresenter $presenter): Response
    {
        $user = $this->member();
        $poll = $save->create($user, $this->payload($request));

        return $this->jsonData($presenter->detail($poll, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/polls/{id}', name: 'api_poll_update', methods: ['PUT'])]
    public function update(string $id, Request $request, SavePoll $save, PollPresenter $presenter): Response
    {
        $user = $this->member();
        $poll = $save->update($user, $id, $this->payload($request));

        return $this->jsonData($presenter->detail($poll, $user));
    }

    #[Route('/api/v1/polls/{id}/answers', name: 'api_poll_answer', methods: ['POST'])]
    public function answer(string $id, Request $request, RecordPollAnswer $record, PollCatalog $catalog, PollPresenter $presenter): Response
    {
        $user = $this->member();
        $record($user, $id, $this->payload($request));

        return $this->jsonData($presenter->detail($catalog->visible($id, $user), $user));
    }

    #[Route('/api/v1/polls/{id}/event', name: 'api_poll_adopt', methods: ['POST'])]
    public function adopt(string $id, Request $request, AdoptPoll $adopt, EventPresenter $presenter): Response
    {
        $user = $this->member();
        $events = $adopt($user, $id, $this->payload($request));

        return $this->jsonData([
            'events' => array_map(fn ($event): array => $presenter->detail($event, $user), $events),
        ], Response::HTTP_CREATED);
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
