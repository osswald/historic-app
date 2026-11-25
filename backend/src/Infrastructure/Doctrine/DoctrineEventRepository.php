<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Event> */
final class DoctrineEventRepository extends ServiceEntityRepository implements EventRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    public function add(Event $event): void
    {
        $this->getEntityManager()->persist($event);
    }

    public function byId(string $id): ?Event
    {
        return $this->find($id);
    }

    public function all(): array
    {
        return $this->findBy([], ['createdAt' => 'ASC']);
    }
}
