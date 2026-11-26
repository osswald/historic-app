<?php

declare(strict_types=1);

namespace App\Domain\Audit;

interface AuditEntryRepository
{
    /**
     * @param list<string> $entityIds
     * @return list<AuditEntry>
     */
    public function forResources(string $entityType, array $entityIds): array;

    /**
     * The event row, linked rows that name this event, and attendance rows that still belong to it.
     *
     * @param list<string> $responseIds
     * @return list<AuditEntry>
     */
    public function forEvent(string $eventId, array $responseIds): array;
}
