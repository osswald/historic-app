<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Fleet\FleetPresenter;
use App\Application\Fleet\RecordBatteryCharge;
use App\Application\Fleet\RecordMeterReading;
use App\Application\Fleet\SaveVehicle;
use App\Domain\Fleet\VehicleRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class VehicleController extends ApiController
{
    #[Route('/api/v1/vehicles', name: 'api_vehicles', methods: ['GET'])]
    public function index(VehicleRepository $vehicles, FleetPresenter $presenter): Response
    {
        $user = $this->member();

        return $this->jsonData($presenter->list($vehicles->all(), $user));
    }

    #[Route('/api/v1/vehicles', name: 'api_vehicle_create', methods: ['POST'])]
    public function create(Request $request, SaveVehicle $save, FleetPresenter $presenter): Response
    {
        $user = $this->member();
        $vehicle = $save->create($user, $this->payload($request));

        return $this->jsonData($presenter->one($vehicle, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/vehicles/{id}', name: 'api_vehicle_update', methods: ['PUT'])]
    public function update(string $id, Request $request, SaveVehicle $save, FleetPresenter $presenter): Response
    {
        $user = $this->member();
        $vehicle = $save->update($user, $id, $this->payload($request));

        return $this->jsonData($presenter->one($vehicle, $user));
    }

    #[Route('/api/v1/vehicles/{id}/battery-charges', name: 'api_vehicle_battery', methods: ['POST'])]
    public function charge(string $id, Request $request, RecordBatteryCharge $record, FleetPresenter $presenter): Response
    {
        $user = $this->member();
        $vehicle = $record($user, $id, $this->payload($request));

        return $this->jsonData($presenter->one($vehicle, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/vehicles/{id}/meter-readings', name: 'api_vehicle_meter', methods: ['POST'])]
    public function reading(string $id, Request $request, RecordMeterReading $record, FleetPresenter $presenter): Response
    {
        $user = $this->member();
        $vehicle = $record($user, $id, $this->payload($request));

        return $this->jsonData($presenter->one($vehicle, $user), Response::HTTP_CREATED);
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
