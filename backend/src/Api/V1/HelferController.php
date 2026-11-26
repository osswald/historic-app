<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Helfer\HelferBaseInvalid;
use App\Application\Helfer\HelferNotConfigured;
use App\Application\Helfer\HelferNotFound;
use App\Application\Helfer\HelferPersonUnknown;
use App\Application\Helfer\HelferRejected;
use App\Application\Helfer\ShowHelfer;
use App\Application\UpstreamUnavailable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final class HelferController extends ApiController
{
    #[Route('/api/v1/helfer/me', name: 'api_helfer_me', methods: ['GET'])]
    public function mine(ShowHelfer $helfer): Response
    {
        return $this->jsonData($helfer->mine($this->member()));
    }

    #[Route('/api/v1/events/{id}/helfer', name: 'api_event_helfer', methods: ['GET'])]
    public function event(string $id, ShowHelfer $helfer): Response
    {
        return $this->guard(fn (): array => $helfer->forEvent($this->member(), $id));
    }

    #[Route('/api/v1/helfer/events', name: 'api_helfer_events', methods: ['GET'])]
    public function events(ShowHelfer $helfer): Response
    {
        return $this->jsonData($helfer->catalogue($this->member()));
    }

    #[Route('/api/v1/events/{id}/helfer', name: 'api_event_helfer_publish', methods: ['POST'])]
    public function publish(string $id, ShowHelfer $helfer): Response
    {
        return $this->guard(fn (): array => $helfer->publish($this->member(), $id));
    }

    #[Route('/api/v1/events/{id}/helfer/link', name: 'api_event_helfer_link', methods: ['POST'])]
    public function link(string $id, Request $request, ShowHelfer $helfer): Response
    {
        try {
            $payload = json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }
        $helferEventId = is_array($payload) ? ($payload['helferEventId'] ?? null) : null;
        if (!is_string($helferEventId) || $helferEventId === '') {
            throw new HttpException(400, 'Die Helferplanung fehlt.');
        }

        return $this->guard(fn (): array => $helfer->link($this->member(), $id, $helferEventId));
    }

    /** @param callable(): array<string, mixed> $action */
    private function guard(callable $action): Response
    {
        try {
            return $this->jsonData($action());
        } catch (HelferNotConfigured|HelferBaseInvalid $exception) {
            throw new HttpException(503, $exception->getMessage());
        } catch (HelferRejected|UpstreamUnavailable $exception) {
            throw new HttpException(502, $exception->getMessage());
        } catch (HelferPersonUnknown $exception) {
            throw new HttpException(403, $exception->getMessage());
        } catch (HelferNotFound $exception) {
            throw new HttpException(404, $exception->getMessage());
        }
    }
}
