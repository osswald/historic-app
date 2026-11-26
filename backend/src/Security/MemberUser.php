<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Event\Club;
use Symfony\Component\Security\Core\User\UserInterface;

final class MemberUser implements UserInterface
{
    /** @var list<string> */
    private array $clubs;

    private ?string $spondId;

    private ?string $email;

    /**
     * @param list<string> $groups
     * @param list<string> $clubs
     */
    public function __construct(
        private string $sub,
        private string $displayName,
        private array $groups,
        private ?string $picture = null,
        array $clubs = [],
        ?string $spondId = null,
        ?string $email = null,
    ) {
        $this->picture = self::normalizePicture($picture);
        $this->clubs = self::normalizeClubs($clubs);
        $this->spondId = self::normalizeSpondId($spondId);
        $this->email = self::normalizeEmail($email);
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

    public function spondId(): ?string
    {
        return $this->spondId;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    /** @return list<Club> */
    public function clubs(): array
    {
        $clubs = [];
        foreach ($this->clubs as $value) {
            $club = Club::tryFrom($value);
            if ($club instanceof Club) {
                $clubs[] = $club;
            }
        }

        return $clubs;
    }

    public function belongsTo(Club $club): bool
    {
        return in_array($club->value, $this->clubs, true);
    }

    /** @param list<Club> $clubs */
    public function sharesClub(array $clubs): bool
    {
        foreach ($clubs as $club) {
            if ($this->belongsTo($club)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $clubs
     *  @return list<string>
     */
    public static function normalizeClubs(array $clubs): array
    {
        $values = [];
        foreach ($clubs as $club) {
            if (!is_string($club)) {
                continue;
            }
            $match = Club::tryFrom(trim($club));
            if ($match instanceof Club) {
                $values[] = $match->value;
            }
        }

        return array_values(array_unique($values));
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

    public static function normalizeSpondId(mixed $spondId): ?string
    {
        if (!is_string($spondId)) {
            return null;
        }
        $spondId = trim($spondId);
        if ($spondId === '' || mb_strlen($spondId) > 80) {
            return null;
        }

        return $spondId;
    }

    public static function normalizeEmail(mixed $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $email = trim($email);
        if ($email === '' || strlen($email) > 254 || strpbrk($email, "\r\n\0 \t") !== false || preg_match('/\A[^@\s]+@[^@\s]+\z/', $email) !== 1) {
            return null;
        }

        return $email;
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
            'clubs' => $this->clubs,
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
