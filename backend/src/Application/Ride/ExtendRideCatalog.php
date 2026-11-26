<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Application\InvalidRequest;
use App\Application\NotFound;
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
     * @return array{id: string, name: string, active: bool, used: bool}
     */
    public function addRideType(MemberUser $actor, array $payload): array
    {
        $this->assertAdmin($actor, 'Listen ergänzen dürfen nur Administratoren.');
        $name = $this->name($payload);
        if ($this->rideTypes->byName($name) instanceof RideType) {
            $this->duplicate('Diese Art der Fahrt gibt es bereits.');
        }

        $rideType = new RideType($name, $this->rideTypes->nextSortOrder());
        $this->rideTypes->add($rideType);
        $this->flush('Diese Art der Fahrt gibt es bereits.');

        return $this->rideTypePayload($rideType);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string, active: bool, used: bool}
     */
    public function updateRideType(MemberUser $actor, string $id, array $payload): array
    {
        $this->assertAdmin($actor, 'Listen ändern dürfen nur Administratoren.');
        $rideType = $this->requireRideType($id);
        $rideType->setActive($this->activeFlag($payload));
        $this->entityManager->flush();

        return $this->rideTypePayload($rideType);
    }

    public function deleteRideType(MemberUser $actor, string $id): void
    {
        $this->assertAdmin($actor, 'Listen ändern dürfen nur Administratoren.');
        $rideType = $this->requireRideType($id);
        if ($this->rideTypes->rideCount($rideType) > 0) {
            throw new InvalidRequest(
                ['name' => 'Diese Art der Fahrt wird noch verwendet und kann nicht gelöscht werden.'],
                'Diese Art der Fahrt wird noch verwendet und kann nicht gelöscht werden.',
            );
        }
        $this->rideTypes->remove($rideType);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string, active: bool, used: bool}
     */
    public function addEvu(MemberUser $actor, array $payload): array
    {
        $this->assertAdmin($actor, 'Listen ergänzen dürfen nur Administratoren.');
        $name = $this->name($payload);
        if ($this->evus->byName($name) instanceof Evu) {
            $this->duplicate('Dieses EVU gibt es bereits.');
        }

        $evu = new Evu($name, $this->evus->nextSortOrder());
        $this->evus->add($evu);
        $this->flush('Dieses EVU gibt es bereits.');

        return $this->evuPayload($evu);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string, active: bool, used: bool}
     */
    public function updateEvu(MemberUser $actor, string $id, array $payload): array
    {
        $this->assertAdmin($actor, 'Listen ändern dürfen nur Administratoren.');
        $evu = $this->requireEvu($id);
        $evu->setActive($this->activeFlag($payload));
        $this->entityManager->flush();

        return $this->evuPayload($evu);
    }

    public function deleteEvu(MemberUser $actor, string $id): void
    {
        $this->assertAdmin($actor, 'Listen ändern dürfen nur Administratoren.');
        $evu = $this->requireEvu($id);
        if ($this->evus->rideCount($evu) > 0) {
            throw new InvalidRequest(
                ['name' => 'Dieses EVU wird noch verwendet und kann nicht gelöscht werden.'],
                'Dieses EVU wird noch verwendet und kann nicht gelöscht werden.',
            );
        }
        $this->evus->remove($evu);
        $this->entityManager->flush();
    }

    /**
     * @return array{rideTypes: list<array{id: string, name: string, active: bool, used: bool}>, evus: list<array{id: string, name: string, active: bool, used: bool}>}
     */
    public function show(MemberUser $actor): array
    {
        if (!$actor->isAdmin()) {
            throw new AccessDeniedException('Die Fahrtenlisten sehen nur Administratoren.');
        }

        return [
            'rideTypes' => array_map(fn (RideType $type): array => $this->rideTypePayload($type), $this->rideTypes->all()),
            'evus' => array_map(fn (Evu $evu): array => $this->evuPayload($evu), $this->evus->all()),
        ];
    }

    private function assertAdmin(MemberUser $actor, string $message): void
    {
        if (!$actor->isAdmin()) {
            throw new AccessDeniedException($message);
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

    /** @param array<string, mixed> $payload */
    private function activeFlag(array $payload): bool
    {
        if (!array_key_exists('active', $payload) || !is_bool($payload['active'])) {
            throw new InvalidRequest(['active' => 'Bitte angeben, ob der Eintrag aktiv ist.']);
        }

        return $payload['active'];
    }

    private function requireRideType(string $id): RideType
    {
        $rideType = $this->rideTypes->get($id);
        if (!$rideType instanceof RideType) {
            throw new NotFound('Diese Art der Fahrt gibt es nicht.');
        }

        return $rideType;
    }

    private function requireEvu(string $id): Evu
    {
        $evu = $this->evus->get($id);
        if (!$evu instanceof Evu) {
            throw new NotFound('Dieses EVU gibt es nicht.');
        }

        return $evu;
    }

    /** @return array{id: string, name: string, active: bool, used: bool} */
    private function rideTypePayload(RideType $rideType): array
    {
        return [
            'id' => $rideType->id(),
            'name' => $rideType->name(),
            'active' => $rideType->isActive(),
            'used' => $this->rideTypes->rideCount($rideType) > 0,
        ];
    }

    /** @return array{id: string, name: string, active: bool, used: bool} */
    private function evuPayload(Evu $evu): array
    {
        return [
            'id' => $evu->id(),
            'name' => $evu->name(),
            'active' => $evu->isActive(),
            'used' => $this->evus->rideCount($evu) > 0,
        ];
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
