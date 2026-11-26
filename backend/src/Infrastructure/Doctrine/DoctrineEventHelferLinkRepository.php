<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\EventHelferLink;
use App\Domain\Event\EventHelferLinkRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventHelferLink> */
final class DoctrineEventHelferLinkRepository extends ServiceEntityRepository implements EventHelferLinkRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventHelferLink::class);
    }

    public function add(EventHelferLink $link): void
    {
        $this->getEntityManager()->persist($link);
    }

    public function remove(EventHelferLink $link): void
    {
        $this->getEntityManager()->remove($link);
    }

    public function forEvent(string $eventId): ?EventHelferLink
    {
        $link = $this->findOneBy(['event' => $eventId]);

        return $link instanceof EventHelferLink ? $link : null;
    }
}
