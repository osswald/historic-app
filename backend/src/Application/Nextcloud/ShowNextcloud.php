<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventNextcloudFolder;
use App\Domain\Event\EventNextcloudFolderRepository;
use App\Domain\Gastronomy\GastroPlanRepository;
use App\Domain\Nextcloud\EventFolderName;
use App\Domain\Nextcloud\EventHasNoDate;
use App\Domain\Ride\RideRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ShowNextcloud
{
    public function __construct(
        private EventCatalog $events,
        private EventNextcloudFolderRepository $folders,
        private GastroPlanRepository $plans,
        private RideRepository $rides,
        private NextcloudClient $client,
        private NextcloudAreas $areas,
        private NextcloudCredentials $credentials,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forEvent(MemberUser $member, string $eventId): array
    {
        $event = $this->events->visible($eventId, $member);
        $canEdit = $member->isOrganizer();
        $link = $this->folders->forEvent($event->id());
        try {
            $auth = $this->credentials->resolve($this->credentials->fromProcess());
        } catch (NextcloudNotConfigured $exception) {
            return $this->view($event, $canEdit, false, $exception->missing, $exception->getMessage(), $link, [], [], false, null);
        } catch (NextcloudBaseInvalid $exception) {
            return $this->view($event, $canEdit, false, ['NEXTCLOUD_BASE'], $exception->getMessage(), $link, [], [], false, null);
        }

        if (!$link instanceof EventNextcloudFolder) {
            return $this->view($event, $canEdit, true, [], null, null, [], [], false, null);
        }

        try {
            $found = $this->client->findByFileId($auth['base'], $auth['user'], $auth['password'], $link->fileId());
            if (!$found instanceof NextcloudEntry || !$found->isCollection()) {
                return $this->view($event, $canEdit, true, [], null, $link, [], [], false, null);
            }
            $children = $this->client->children($auth['base'], $auth['user'], $auth['password'], $found->path());
        } catch (NextcloudNotFound) {
            return $this->view($event, $canEdit, true, [], null, $link, [], [], false, null);
        } catch (NextcloudBaseInvalid $exception) {
            return $this->view($event, $canEdit, true, ['NEXTCLOUD_BASE'], $exception->getMessage(), $link, [], [], false, null);
        } catch (NextcloudRejected|UpstreamUnavailable $exception) {
            return $this->view($event, $canEdit, true, [], $exception->getMessage(), $link, [], [], false, null);
        }

        $documents = [];
        foreach ($children as $child) {
            if ($child->isCollection()) {
                continue;
            }
            $row = $this->linkFor($auth['base'], $child, $found->path());
            if ($row !== null) {
                $documents[] = $row;
            }
        }
        $areaRows = [];
        $hasGastro = $this->plans->byEventId($event->id()) !== null;
        $hasRide = $this->rides->forEvent($event->id()) !== [];
        foreach ($this->areas->select($hasGastro, $hasRide, $children) as $area) {
            $row = $this->linkFor($auth['base'], $area, $found->path());
            if ($row === null) {
                continue;
            }
            $row['key'] = $area->name() === 'Gastronomie' ? 'gastronomie' : 'fahrtenplanung';
            $areaRows[] = $row;
        }

        return $this->view(
            $event,
            $canEdit,
            true,
            [],
            null,
            $link,
            $documents,
            $areaRows,
            true,
            $this->client->fileUrl($auth['base'], $found->fileId(), $found->path()),
        );
    }

    /** @return list<array{fileId: string, name: string}> */
    public function choices(MemberUser $member, string $eventId): array
    {
        $this->assertOrganizer($member);
        $this->events->visible($eventId, $member);
        $auth = $this->connection();
        $choices = [];
        foreach ($this->client->eventFolders($auth['base'], $auth['user'], $auth['password']) as $folder) {
            $choices[] = ['fileId' => $folder->fileId(), 'name' => $folder->name()];
        }

        return $choices;
    }

    /** @return array<string, mixed> */
    public function link(MemberUser $member, string $eventId, string $fileId): array
    {
        $this->assertOrganizer($member);
        if (preg_match('/^[0-9]{1,20}$/', $fileId) !== 1) {
            throw new InvalidRequest(['fileId' => 'Die Nextcloud-Kennung ist ungültig.']);
        }
        $event = $this->events->visible($eventId, $member);
        $this->assertFree($event);
        $auth = $this->connection();
        $match = null;
        foreach ($this->client->eventFolders($auth['base'], $auth['user'], $auth['password']) as $folder) {
            if ($folder->fileId() === $fileId) {
                $match = $folder;
                break;
            }
        }
        if (!$match instanceof NextcloudEntry) {
            throw new NextcloudNotFound('Der Ordner steht nicht direkt unter Fahrten_Anlässe.');
        }
        $this->remember($event, $match, $member, 'folder_linked');

        return $this->forEvent($member, $event->id());
    }

    /** @return array<string, mixed> */
    public function create(MemberUser $member, string $eventId): array
    {
        $this->assertOrganizer($member);
        $event = $this->events->visible($eventId, $member);
        $this->assertFree($event);
        try {
            $name = EventFolderName::forEvent($event);
        } catch (EventHasNoDate $exception) {
            throw new InvalidRequest(['days' => $exception->getMessage()]);
        }
        $auth = $this->connection();
        $created = $this->client->createEventFolder($auth['base'], $auth['user'], $auth['password'], $name);
        $this->remember($event, $created, $member, 'folder_created');

        return $this->forEvent($member, $event->id());
    }

    /** @return array<string, mixed> */
    public function addFile(MemberUser $member, string $eventId, string $filename, string $contents): array
    {
        $event = $this->events->visible($eventId, $member);
        $link = $this->folders->forEvent($event->id());
        if (!$link instanceof EventNextcloudFolder) {
            throw new NextcloudNotFound('Dieser Anlass hat keinen Nextcloud-Ordner.');
        }
        $name = $this->uploadName($filename);
        $auth = $this->connection();
        $found = $this->client->findByFileId($auth['base'], $auth['user'], $auth['password'], $link->fileId());
        if (!$found instanceof NextcloudEntry || !$found->isCollection() || $found->fileId() !== $link->fileId()) {
            throw new NextcloudNotFound('Der Ordner ist in Nextcloud nicht vorhanden.');
        }
        $folder = trim($found->path(), '/');
        if (!str_starts_with($folder, NextcloudClient::ROOT.'/') || str_contains($folder, '..')) {
            throw new NextcloudNotFound('Der Ordner ist in Nextcloud nicht vorhanden.');
        }
        $this->client->upload($auth['base'], $auth['user'], $auth['password'], $folder, $name, $contents);
        $this->audit->record(
            $member->getUserIdentifier(),
            'event',
            $event->id(),
            'file_added',
            null,
            ['file' => $name],
            $this->clock->now(),
            $member->displayName(),
        );
        $this->entityManager->flush();

        return $this->forEvent($member, $event->id());
    }

    /** @return array{base: string, user: string, password: string} */
    private function connection(): array
    {
        return $this->credentials->resolve($this->credentials->fromProcess());
    }

    private function assertOrganizer(MemberUser $member): void
    {
        if (!$member->isOrganizer()) {
            throw new AccessDeniedException('Nur die Organisation verknüpft einen Nextcloud-Ordner.');
        }
    }

    private function assertFree(Event $event): void
    {
        if ($this->folders->forEvent($event->id()) instanceof EventNextcloudFolder) {
            throw new InvalidRequest(['fileId' => 'Dieser Anlass hat schon einen Nextcloud-Ordner.']);
        }
    }

    private function remember(Event $event, NextcloudEntry $entry, MemberUser $member, string $action): void
    {
        if (preg_match('/^[0-9]{1,20}$/', $entry->fileId()) !== 1 || str_contains($entry->fileId(), '/')) {
            throw new UpstreamUnavailable('Nextcloud hat keine Datei-Kennung geliefert.');
        }
        $name = $entry->name();
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new UpstreamUnavailable('Nextcloud hat keinen Ordnernamen geliefert.');
        }
        if (mb_strlen($name) > 255) {
            $name = rtrim(mb_substr($name, 0, 255));
        }
        $ocId = $entry->ocId();
        if ($ocId !== null && preg_match('/^[0-9]+oc[A-Za-z0-9]+$/', $ocId) !== 1) {
            $ocId = null;
        }
        $this->folders->add(new EventNextcloudFolder($event, $entry->fileId(), $ocId, $name, $this->clock->now()));
        $this->audit->record(
            $member->getUserIdentifier(),
            'event',
            $event->id(),
            $action,
            null,
            ['folder' => $name],
            $this->clock->now(),
            $member->displayName(),
        );
        $this->entityManager->flush();
    }

    private function uploadName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidRequest(['file' => 'Bitte eine Datei wählen.'], 'Bitte eine Datei wählen.');
        }
        if ($name === '.' || $name === '..' || str_contains($name, '/') || strpbrk($name, "\0\r\n") !== false || mb_strlen($name) > 255) {
            throw new InvalidRequest(['file' => 'Der Dateiname ist ungültig.'], 'Der Dateiname ist ungültig.');
        }

        return $name;
    }

    /** @return array{name: string, fileId: string, url: string}|null */
    private function linkFor(string $base, NextcloudEntry $entry, string $folderPath): ?array
    {
        if (preg_match('/^[0-9]{1,20}$/', $entry->fileId()) !== 1) {
            return null;
        }
        $directory = $entry->isCollection() ? $entry->path() : $folderPath;
        try {
            $url = $this->client->fileUrl($base, $entry->fileId(), $directory, !$entry->isCollection());
        } catch (UpstreamUnavailable) {
            return null;
        }
        $host = parse_url($base, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !is_string($host) || parse_url($url, PHP_URL_HOST) !== $host) {
            return null;
        }

        return [
            'name' => $entry->name(),
            'fileId' => $entry->fileId(),
            'url' => $url,
        ];
    }

    /**
     * @param list<string> $missing
     * @param list<array<string, string>> $documents
     * @param list<array<string, string>> $areas
     * @return array<string, mixed>
     */
    private function view(
        Event $event,
        bool $canEdit,
        bool $configured,
        array $missing,
        ?string $notice,
        ?EventNextcloudFolder $link,
        array $documents,
        array $areas,
        bool $reachable,
        ?string $url,
    ): array {
        $linked = $link instanceof EventNextcloudFolder;
        $proposed = null;
        if ($canEdit && $configured && !$linked) {
            try {
                $proposed = EventFolderName::forEvent($event);
            } catch (EventHasNoDate) {
                $proposed = null;
            }
        }

        return [
            'configured' => $configured,
            'missing' => $missing,
            'notice' => $notice,
            'canEdit' => $canEdit,
            'linked' => $linked,
            'reachable' => $linked && $reachable,
            'name' => $linked ? $link->name() : null,
            'fileId' => $linked ? $link->fileId() : null,
            'url' => $linked && $reachable ? $url : null,
            'proposedName' => $proposed,
            'documents' => $documents,
            'areas' => $areas,
        ];
    }
}
