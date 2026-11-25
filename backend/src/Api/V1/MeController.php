<?php

declare(strict_types=1);

namespace App\Api\V1;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MeController extends ApiController
{
    #[Route('/api/v1/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->jsonData($this->member()->profile());
    }
}
