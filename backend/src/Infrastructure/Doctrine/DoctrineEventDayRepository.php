<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\EventDay;
use App\Domain\Event\EventDayRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventDay> */
final class DoctrineEventDayRepository extends ServiceEntityRepository implements EventDayRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventDay::class);
    }

    public function byId(string $id): ?EventDay
    {
        return $this->find($id);
    }
}
