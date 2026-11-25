<?php

declare(strict_types=1);

namespace App\Api\V1;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends ApiController
{
    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->jsonData(['status' => 'ok']);
    }
}
