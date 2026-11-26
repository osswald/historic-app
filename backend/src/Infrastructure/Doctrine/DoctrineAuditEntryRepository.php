<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    public function forEvent(string $eventId, array $responseIds): array
    {
        $params = [
            'eventId' => $eventId,
            'linkedTypes' => ['ride', 'event_ride_plan', 'gastro_plan', 'event_response'],
        ];
        $types = ['linkedTypes' => ArrayParameterType::STRING];
        $responseSql = '';
        if ($responseIds !== []) {
            $responseSql = " OR (entity_type = 'event_response' AND entity_id IN (:responseIds))";
            $params['responseIds'] = array_values($responseIds);
            $types['responseIds'] = ArrayParameterType::STRING;
        }

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT id FROM audit_entry
             WHERE (entity_type = 'event' AND entity_id = :eventId)
                OR (entity_type IN (:linkedTypes) AND (
                    after_data->>'eventId' = :eventId
                    OR before_data->>'eventId' = :eventId
                ))".$responseSql.'
             ORDER BY occurred_at DESC, id DESC',
            $params,
            $types,
        );
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $entry) {
            $byId[$entry->id()] = $entry;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
