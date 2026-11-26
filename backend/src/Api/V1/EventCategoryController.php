<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Event\ExtendEventCategoryCatalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EventCategoryController extends ApiController
{
    #[Route('/api/v1/event-categories', name: 'api_event_categories', methods: ['GET'])]
    public function catalog(ExtendEventCategoryCatalog $catalog): Response
    {
        return $this->jsonData($catalog->show($this->member()));
    }

    #[Route('/api/v1/event-categories', name: 'api_event_categories_add', methods: ['POST'])]
    public function add(Request $request, ExtendEventCategoryCatalog $catalog): Response
    {
        return $this->jsonData($catalog->add($this->member(), $this->payload($request)), 201);
    }

    #[Route('/api/v1/event-categories/{id}', name: 'api_event_categories_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, ExtendEventCategoryCatalog $catalog): Response
    {
        return $this->jsonData($catalog->update($this->member(), $id, $this->payload($request)));
    }

    #[Route('/api/v1/event-categories/{id}', name: 'api_event_categories_delete', methods: ['DELETE'])]
    public function delete(string $id, ExtendEventCategoryCatalog $catalog): Response
    {
        $catalog->delete($this->member(), $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
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
