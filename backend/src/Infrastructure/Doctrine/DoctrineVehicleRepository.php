<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Vehicle> */
final class DoctrineVehicleRepository extends ServiceEntityRepository implements VehicleRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    public function add(Vehicle $vehicle): void
    {
        $this->getEntityManager()->persist($vehicle);
    }

    public function get(string $id): ?Vehicle
    {
        return $this->find($id);
    }

    public function all(): array
    {
        /** @var list<Vehicle> $vehicles */
        $vehicles = $this->createQueryBuilder('vehicle')
            ->orderBy('vehicle.sortOrder', 'ASC')
            ->addOrderBy('vehicle.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $vehicles;
    }
}
