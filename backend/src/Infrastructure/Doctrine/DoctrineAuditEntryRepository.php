<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEntry> */
final class DoctrineAuditEntryRepository extends ServiceEntityRepository implements AuditEntryRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    public function forResources(string $entityType, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        /** @var list<AuditEntry> $entries */
        $entries = $this->createQueryBuilder('entry')
            ->andWhere('entry.entityType = :type')
            ->andWhere('entry.entityId IN (:ids)')
            ->setParameter('type', $entityType)
            ->setParameter('ids', array_values($entityIds))
            ->orderBy('entry.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
