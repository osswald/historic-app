<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\InvalidRequest;
use App\Application\Nextcloud\NextcloudBaseInvalid;
use App\Application\Nextcloud\NextcloudNotConfigured;
use App\Application\Nextcloud\NextcloudNotFound;
use App\Application\Nextcloud\NextcloudRejected;
use App\Application\Nextcloud\ShowNextcloud;
use App\Application\UpstreamUnavailable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final class NextcloudController extends ApiController
{
    #[Route('/api/v1/events/{id}/nextcloud', name: 'api_event_nextcloud', methods: ['GET'])]
    public function show(string $id, ShowNextcloud $nextcloud): Response
    {
        return $this->jsonData($nextcloud->forEvent($this->member(), $id));
    }

    #[Route('/api/v1/events/{id}/nextcloud/folders', name: 'api_event_nextcloud_folders', methods: ['GET'])]
    public function folders(string $id, ShowNextcloud $nextcloud): Response
    {
        return $this->guard(fn (): array => ['folders' => $nextcloud->choices($this->member(), $id)]);
    }

    #[Route('/api/v1/events/{id}/nextcloud', name: 'api_event_nextcloud_save', methods: ['POST'])]
    public function save(string $id, Request $request, ShowNextcloud $nextcloud): Response
    {
        try {
            $payload = json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }
        if (!is_array($payload)) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }
        $fileId = $payload['fileId'] ?? null;
        if (is_string($fileId) && $fileId !== '') {
            return $this->guard(fn (): array => $nextcloud->link($this->member(), $id, $fileId));
        }
        if (($payload['create'] ?? false) === true) {
            return $this->guard(fn (): array => $nextcloud->create($this->member(), $id));
        }

        throw new HttpException(400, 'Der Nextcloud-Ordner fehlt.');
    }

    #[Route('/api/v1/events/{id}/nextcloud/files', name: 'api_event_nextcloud_file', methods: ['POST'])]
    public function upload(string $id, Request $request, ShowNextcloud $nextcloud): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new InvalidRequest(['file' => 'Bitte eine Datei wählen.'], 'Bitte eine Datei wählen.');
        }
        if (!$file->isValid()) {
            $message = $this->uploadMessage($file);

            throw new InvalidRequest(['file' => $message], $message);
        }
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            throw new InvalidRequest(['file' => 'Die Datei konnte nicht gelesen werden.'], 'Die Datei konnte nicht gelesen werden.');
        }

        return $this->guard(fn (): array => $nextcloud->addFile($this->member(), $id, $file->getClientOriginalName(), $contents));
    }

    private function uploadMessage(UploadedFile $file): string
    {
        return match ($file->getError()) {
            \UPLOAD_ERR_NO_FILE => 'Bitte eine Datei wählen.',
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu gross.',
            default => 'Die Datei konnte nicht gelesen werden.',
        };
    }

    /** @param callable(): array<string, mixed> $action */
    private function guard(callable $action): Response
    {
        try {
            return $this->jsonData($action());
        } catch (NextcloudNotConfigured|NextcloudBaseInvalid $exception) {
            throw new HttpException(503, $exception->getMessage());
        } catch (NextcloudRejected|UpstreamUnavailable $exception) {
            throw new HttpException(502, $exception->getMessage());
        } catch (NextcloudNotFound $exception) {
            throw new HttpException(404, $exception->getMessage());
        }
    }
}
