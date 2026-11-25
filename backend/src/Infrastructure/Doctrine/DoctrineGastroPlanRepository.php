<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Gastronomy\GastroPlan;
use App\Domain\Gastronomy\GastroPlanRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GastroPlan> */
final class DoctrineGastroPlanRepository extends ServiceEntityRepository implements GastroPlanRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GastroPlan::class);
    }

    public function add(GastroPlan $plan): void
    {
        $this->getEntityManager()->persist($plan);
    }

    public function byEventId(string $eventId): ?GastroPlan
    {
        $plan = $this->findOneBy(['event' => $eventId]);

        return $plan instanceof GastroPlan ? $plan : null;
    }
}
