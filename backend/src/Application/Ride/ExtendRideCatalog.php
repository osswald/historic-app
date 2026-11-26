<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Application\InvalidRequest;
use App\Domain\Ride\Evu;
use App\Domain\Ride\EvuRepository;
use App\Domain\Ride\RideType;
use App\Domain\Ride\RideTypeRepository;
use App\Security\MemberUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ExtendRideCatalog
{
    public function __construct(
        private RideTypeRepository $rideTypes,
        private EvuRepository $evus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string}
     */
    public function addRideType(MemberUser $actor, array $payload): array
    {
        $this->assertAdmin($actor);
        $name = $this->name($payload);
        if ($this->rideTypes->byName($name) instanceof RideType) {
            $this->duplicate('Diese Art der Fahrt gibt es bereits.');
        }

        $rideType = new RideType($name, $this->rideTypes->nextSortOrder());
        $this->rideTypes->add($rideType);
        $this->flush('Diese Art der Fahrt gibt es bereits.');

        return ['id' => $rideType->id(), 'name' => $rideType->name()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string}
     */
    public function addEvu(MemberUser $actor, array $payload): array
    {
        $this->assertAdmin($actor);
        $name = $this->name($payload);
        if ($this->evus->byName($name) instanceof Evu) {
            $this->duplicate('Dieses EVU gibt es bereits.');
        }

        $evu = new Evu($name, $this->evus->nextSortOrder());
        $this->evus->add($evu);
        $this->flush('Dieses EVU gibt es bereits.');

        return ['id' => $evu->id(), 'name' => $evu->name()];
    }

    private function assertAdmin(MemberUser $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new AccessDeniedException('Listen ergänzen dürfen nur Administratoren.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function name(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidRequest(['name' => 'Bitte einen Namen angeben.']);
        }
        if (mb_strlen($name) > 80) {
            throw new InvalidRequest(['name' => 'Bitte höchstens 80 Zeichen angeben.']);
        }

        return $name;
    }

    /** @return never */
    private function duplicate(string $message): void
    {
        throw new InvalidRequest(['name' => $message], $message);
    }

    private function flush(string $duplicate): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->duplicate($duplicate);
        }
    }
}
