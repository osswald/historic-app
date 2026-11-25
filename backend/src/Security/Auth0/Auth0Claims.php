<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use App\Security\MemberUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Auth0Claims
{
    /** @param Key|\ArrayAccess<string, Key> $keys */
    public function decode(string $jwt, Key|\ArrayAccess $keys, string $issuer, string $audience): \stdClass
    {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                throw new Auth0TokenException('Token ungültig.');
            }
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
            if (!is_object($header) || ($header->alg ?? null) !== 'RS256') {
                throw new Auth0TokenException('Nur RS256-Token sind erlaubt.');
            }
            JWT::$leeway = 60;
            $decoded = JWT::decode($jwt, $keys);
        } catch (Auth0TokenException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new Auth0TokenException('Token ungültig.');
        }

        if (($decoded->iss ?? null) !== $issuer) {
            throw new Auth0TokenException('Aussteller ungültig.');
        }
        $audiences = $decoded->aud ?? null;
        $audiences = is_array($audiences) ? $audiences : [$audiences];
        if (!in_array($audience, $audiences, true)) {
            throw new Auth0TokenException('Zielgruppe ungültig.');
        }

        return $decoded;
    }

    public function member(\stdClass $access, ?\stdClass $id, string $groupsClaim): MemberUser
    {
        $sub = trim((string) ($access->sub ?? ''));
        if ($sub === '') {
            throw new Auth0TokenException('Token ohne Benutzer.');
        }
        if ($id !== null && trim((string) ($id->sub ?? '')) !== $sub) {
            throw new Auth0TokenException('Token ungültig.');
        }

        return new MemberUser($sub, $this->name($access, $id, $sub), $this->groups($access, $groupsClaim));
    }

    /** @return list<string> */
    private function groups(\stdClass $access, string $groupsClaim): array
    {
        $claim = $groupsClaim !== '' ? ($access->{$groupsClaim} ?? null) : null;
        $groups = $claim ?? ($access->groups ?? []);
        if (is_string($groups)) {
            $groups = [$groups];
        }
        if (!is_array($groups)) {
            return [];
        }

        $names = [];
        foreach ($groups as $group) {
            if (!is_string($group)) {
                continue;
            }
            $group = trim($group);
            if ($group !== '') {
                $names[] = $group;
            }
        }

        return array_values(array_unique($names));
    }

    private function name(\stdClass $access, ?\stdClass $id, string $sub): string
    {
        foreach ([$access, $id] as $token) {
            if (!$token instanceof \stdClass) {
                continue;
            }
            foreach (['name', 'nickname', 'email'] as $claim) {
                $value = trim((string) ($token->{$claim} ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return $sub;
    }
}
