<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Ride\EventRidePlan;
use App\Domain\Ride\EventRidePlanRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventRidePlan> */
final class DoctrineEventRidePlanRepository extends ServiceEntityRepository implements EventRidePlanRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventRidePlan::class);
    }

    public function add(EventRidePlan $plan): void
    {
        $this->getEntityManager()->persist($plan);
    }

    public function forEvent(string $eventId): ?EventRidePlan
    {
        $plan = $this->findOneBy(['event' => $eventId]);

        return $plan instanceof EventRidePlan ? $plan : null;
    }
}
