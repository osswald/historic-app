<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

use App\Domain\Event\Event;
use App\Domain\Event\EventNextcloudFolder;
use App\Domain\Nextcloud\ArchiveEventFolder;

/**
 * Verschiebt einen fälligen Ordner nach Fahrten_Anlässe/_ARCHIV/YYYY.
 * Dieser Dienst wird nicht gegen die produktive Nextcloud ausgeführt.
 */
final class ArchiveLinkedFolder
{
    public function __construct(
        private ArchiveEventFolder $policy,
        private NextcloudClient $client,
    ) {
    }

    public function moveIfDue(Event $event, EventNextcloudFolder $link, \DateTimeImmutable $today, string $base, string $user, string $password): bool
    {
        $directory = $this->policy->directory($event, $today);
        if ($directory === null) {
            return false;
        }
        $found = $this->client->findByFileId($base, $user, $password, $link->fileId());
        if (!$found instanceof NextcloudEntry || !$found->isCollection()) {
            return false;
        }
        $name = $found->name() !== '' ? $found->name() : $link->name();
        $destination = $directory.'/'.$name;
        if ($found->path() === $destination) {
            return false;
        }
        $this->client->ensureDirectory($base, $user, $password, $directory);
        $this->client->move($base, $user, $password, $found->path(), $destination);

        return true;
    }
}
