<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Gastronomy\GastroCatalog;
use App\Application\Gastronomy\SaveGastroPlan;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GastroController extends ApiController
{
    #[Route('/api/v1/gastronomy', name: 'api_gastronomy', methods: ['GET'])]
    public function index(GastroCatalog $catalog): Response
    {
        return $this->jsonData($catalog->overview($this->member()));
    }

    #[Route('/api/v1/events/{id}/gastronomy', name: 'api_event_gastronomy', methods: ['GET'])]
    public function show(string $id, GastroCatalog $catalog): Response
    {
        return $this->jsonData($catalog->show($this->member(), $id));
    }

    #[Route('/api/v1/events/{id}/gastronomy', name: 'api_event_gastronomy_save', methods: ['PUT'])]
    public function replace(string $id, Request $request, SaveGastroPlan $save): Response
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
