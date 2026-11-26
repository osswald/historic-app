<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Spond\ShowSpondRead;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SpondReadController extends ApiController
{
    #[Route('/api/v1/spond/read', name: 'api_spond_read', methods: ['GET'])]
    public function show(ShowSpondRead $show): Response
    {
        return $this->jsonData($show($this->member()));
    }
}
