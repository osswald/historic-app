<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Dashboard\BuildDashboard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends ApiController
{
    #[Route('/api/v1/dashboard', name: 'api_dashboard', methods: ['GET'])]
    public function __invoke(BuildDashboard $dashboard): Response
    {
        return $this->jsonData($dashboard($this->member()));
    }
}
