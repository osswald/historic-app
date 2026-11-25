<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domain\Audit\AuditEntry;
use Doctrine\ORM\EntityManagerInterface;

final class AuditRecorder
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    /** @param array<string, mixed>|null $before
     *  @param array<string, mixed>|null $after
     */
    public function record(
        string $actorSub,
        string $entityType,
        string $entityId,
        string $action,
        ?array $before,
        ?array $after,
        \DateTimeImmutable $at,
    ): void {
        $this->entities->persist(new AuditEntry(
            $actorSub,
            $entityType,
            $entityId,
            $action,
            $before,
            $after,
            $at,
        ));
    }
}
