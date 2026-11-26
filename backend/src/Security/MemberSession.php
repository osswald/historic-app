<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Event\Club;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class MemberSession
{
    public const KEY = 'auth0_member';
    public const STATE = 'auth0_state';

    public function __construct(
        #[Autowire('%app.session_cookie%')]
        private string $cookieName,
    ) {
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    public function rememberState(Request $request, string $state): void
    {
        $request->getSession()->set(self::STATE, $state);
    }

    public function pullState(Request $request): ?string
    {
        if (!$request->cookies->has($this->cookieName)) {
            return null;
        }
        $session = $request->getSession();
        $state = $session->get(self::STATE);
        $session->remove(self::STATE);

        return is_string($state) && $state !== '' ? $state : null;
    }

    public function store(Request $request, MemberUser $user): void
    {
        $session = $request->getSession();
        $session->migrate(true);
        $payload = [
            'sub' => $user->getUserIdentifier(),
            'displayName' => $user->displayName(),
            'groups' => $user->groups(),
            'clubs' => array_map(static fn (Club $club): string => $club->value, $user->clubs()),
        ];
        $picture = $user->picture();
        if ($picture !== null) {
            $payload['picture'] = $picture;
        }
        $session->set(self::KEY, $payload);
    }

    public function read(Request $request): ?MemberUser
    {
        if (!$request->cookies->has($this->cookieName)) {
            return null;
        }
        $session = $request->getSession();
        if (!$session->has(self::KEY)) {
            return null;
        }
        $user = $this->userFrom($session->get(self::KEY));
        if (!$user instanceof MemberUser) {
            $session->remove(self::KEY);

            return null;
        }

        return $user;
    }

    public function clear(Request $request): void
    {
        if (!$request->hasSession() && !$request->cookies->has($this->cookieName)) {
            return;
        }
        $request->getSession()->invalidate();
    }

    private function userFrom(mixed $payload): ?MemberUser
    {
        if (!is_array($payload)) {
            return null;
        }
        $sub = $payload['sub'] ?? null;
        $name = $payload['displayName'] ?? null;
        $groups = $payload['groups'] ?? null;
        if (!is_string($sub) || $sub === '' || !is_string($name) || $name === '' || !is_array($groups)) {
            return null;
        }
        $names = [];
        foreach ($groups as $group) {
            if (is_string($group) && $group !== '') {
                $names[] = $group;
            }
        }

        $clubs = [];
        foreach ((array) ($payload['clubs'] ?? []) as $club) {
            if (is_string($club) && $club !== '') {
                $clubs[] = $club;
            }
        }

        return new MemberUser($sub, $name, $names, MemberUser::normalizePicture($payload['picture'] ?? null), $clubs);
    }
}
