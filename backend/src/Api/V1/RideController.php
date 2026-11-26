<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Ride\ExtendRideCatalog;
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

    #[Route('/api/v1/ride-catalog', name: 'api_ride_catalog', methods: ['GET'])]
    public function catalog(ExtendRideCatalog $catalog): Response
    {
        return $this->jsonData($catalog->show($this->member()));
    }

    #[Route('/api/v1/ride-types', name: 'api_ride_types_add', methods: ['POST'])]
    public function addRideType(Request $request, ExtendRideCatalog $catalog): Response
    {
        return $this->jsonData($catalog->addRideType($this->member(), $this->payload($request)), 201);
    }

    #[Route('/api/v1/ride-types/{id}', name: 'api_ride_types_update', methods: ['PATCH'])]
    public function updateRideType(string $id, Request $request, ExtendRideCatalog $catalog): Response
    {
        return $this->jsonData($catalog->updateRideType($this->member(), $id, $this->payload($request)));
    }

    #[Route('/api/v1/ride-types/{id}', name: 'api_ride_types_delete', methods: ['DELETE'])]
    public function deleteRideType(string $id, ExtendRideCatalog $catalog): Response
    {
        $catalog->deleteRideType($this->member(), $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/evus', name: 'api_evus_add', methods: ['POST'])]
    public function addEvu(Request $request, ExtendRideCatalog $catalog): Response
    {
        return $this->jsonData($catalog->addEvu($this->member(), $this->payload($request)), 201);
    }

    #[Route('/api/v1/evus/{id}', name: 'api_evus_update', methods: ['PATCH'])]
    public function updateEvu(string $id, Request $request, ExtendRideCatalog $catalog): Response
    {
        return $this->jsonData($catalog->updateEvu($this->member(), $id, $this->payload($request)));
    }

    #[Route('/api/v1/evus/{id}', name: 'api_evus_delete', methods: ['DELETE'])]
    public function deleteEvu(string $id, ExtendRideCatalog $catalog): Response
    {
        $catalog->deleteEvu($this->member(), $id);

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
