<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\EventTaskListLink;
use App\Domain\Event\EventTaskListRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventTaskListLink> */
final class DoctrineEventTaskListRepository extends ServiceEntityRepository implements EventTaskListRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventTaskListLink::class);
    }

    public function add(EventTaskListLink $link): void
    {
        $this->getEntityManager()->persist($link);
    }

    public function findForEvent(string $eventId, string $taskListId): ?EventTaskListLink
    {
        $link = $this->findOneBy(['event' => $eventId, 'taskListId' => $taskListId]);

        return $link instanceof EventTaskListLink ? $link : null;
    }

    public function forEvent(string $eventId): array
    {
        /** @var list<EventTaskListLink> $links */
        $links = $this->findBy(['event' => $eventId], ['createdAt' => 'ASC']);

        return $links;
    }
}
