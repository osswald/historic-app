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
}
