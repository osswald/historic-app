<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class MemberUser implements UserInterface
{
    /** @param list<string> $groups */
    public function __construct(
        private string $sub,
        private string $displayName,
        private array $groups,
        private ?string $picture = null,
    ) {
        $this->picture = self::normalizePicture($picture);
    }

    public function getUserIdentifier(): string
    {
        return $this->sub;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function picture(): ?string
    {
        return $this->picture;
    }

    public static function normalizePicture(mixed $picture): ?string
    {
        if (!is_string($picture)) {
            return null;
        }
        $picture = trim($picture);
        if ($picture === '' || preg_match('#\Ahttps://#', $picture) !== 1 || preg_match('/\s/', $picture) === 1) {
            return null;
        }
        $parts = parse_url($picture);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($host) || $host === '') {
            return null;
        }

        return $picture;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        $map = [
            'admin' => 'ROLE_ADMIN',
            'Vorstand' => 'ROLE_ORGANIZER',
            'Fahrtenplanung' => 'ROLE_RIDE_PLANNER',
            'Gastro-Team' => 'ROLE_GASTRO',
            'Technische Kommission' => 'ROLE_FLEET',
        ];
        foreach ($this->groups as $group) {
            if (isset($map[$group])) {
                $roles[] = $map[$group];
            }
        }
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ORGANIZER';
        }

        return array_values(array_unique($roles));
    }

    public function isOrganizer(): bool
    {
        return in_array('ROLE_ORGANIZER', $this->getRoles(), true);
    }

    public function isRidePlanner(): bool
    {
        return in_array('ROLE_RIDE_PLANNER', $this->getRoles(), true);
    }

    public function canPlanRides(): bool
    {
        return $this->isAdmin() || $this->isRidePlanner();
    }

    public function isFleet(): bool
    {
        return in_array('ROLE_FLEET', $this->getRoles(), true);
    }

    public function isGastro(): bool
    {
        return in_array('ROLE_GASTRO', $this->getRoles(), true);
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    public function canEditFleet(): bool
    {
        return $this->isFleet() || $this->isAdmin();
    }

    public function eraseCredentials(): void
    {
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        $profile = [
            'sub' => $this->sub,
            'displayName' => $this->displayName,
            'groups' => $this->groups,
            'canOrganize' => $this->isOrganizer(),
            'canPlanRides' => $this->canPlanRides(),
            'canEditFleet' => $this->canEditFleet(),
        ];
        if ($this->picture !== null) {
            $profile['picture'] = $this->picture;
        }

        return $profile;
    }
}
