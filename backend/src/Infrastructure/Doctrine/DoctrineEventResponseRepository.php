<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\EventResponse;
use App\Domain\Event\EventResponseRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventResponse> */
final class DoctrineEventResponseRepository extends ServiceEntityRepository implements EventResponseRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventResponse::class);
    }

    public function bySpondMemberId(string $spondMemberId): array
    {
        return $this->findBy(['spondMemberId' => $spondMemberId]);
    }

    public function idsForEvent(string $eventId): array
    {
        /** @var list<string> $ids */
        $ids = $this->createQueryBuilder('response')
            ->select('response.id')
            ->innerJoin('response.eventDay', 'day')
            ->andWhere('IDENTITY(day.event) = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }
}
