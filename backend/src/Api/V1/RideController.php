<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Ride\RideCatalog;
use App\Application\Ride\SaveRides;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class RideController extends ApiController
{
    #[Route('/api/v1/events/{id}/rides', name: 'api_event_rides', methods: ['GET'])]
    public function show(string $id, RideCatalog $catalog): Response
    {
        return $this->jsonData($catalog->show($this->member(), $id));
    }

    #[Route('/api/v1/events/{id}/rides', name: 'api_event_rides_save', methods: ['PUT'])]
    public function replace(string $id, Request $request, SaveRides $save): Response
    {
        return $this->jsonData($save->replace($this->member(), $id, $this->payload($request)));
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
