<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Infrastructure\Station\OevTrainStopSearch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StationController extends ApiController
{
    #[Route('/api/v1/stations', name: 'api_stations', methods: ['GET'])]
    public function index(Request $request, OevTrainStopSearch $search): Response
    {
        $this->member();

        return $this->jsonData([
            'stations' => $search->search((string) $request->query->get('q', '')),
        ]);
    }
}
