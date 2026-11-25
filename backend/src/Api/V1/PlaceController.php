<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Event\PlaceDirectory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlaceController extends ApiController
{
    #[Route('/api/v1/places', name: 'api_places', methods: ['GET'])]
    public function index(PlaceDirectory $places): Response
    {
        $this->member();

        return $this->jsonData([
            'places' => array_map(static fn ($place): array => [
                'id' => $place->id(),
                'name' => $place->name(),
            ], $places->all()),
        ]);
    }
}
