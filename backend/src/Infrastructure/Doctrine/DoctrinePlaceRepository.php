<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\Place;
use App\Domain\Event\PlaceRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Place> */
final class DoctrinePlaceRepository extends ServiceEntityRepository implements PlaceRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }

    public function add(Place $place): void
    {
        $this->getEntityManager()->persist($place);
    }

    public function byName(string $name): ?Place
    {
        $place = $this->findOneBy(['name' => $name]);

        return $place instanceof Place ? $place : null;
    }

    public function all(): array
    {
        /** @var list<Place> $places */
        $places = $this->createQueryBuilder('place')
            ->orderBy('place.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $places;
    }
}
