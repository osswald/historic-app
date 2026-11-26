<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventNextcloudFolderRepository
{
    public function add(EventNextcloudFolder $folder): void;

    public function forEvent(string $eventId): ?EventNextcloudFolder;
}
