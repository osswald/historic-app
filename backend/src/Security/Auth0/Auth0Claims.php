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

        $groups = $this->groups($access, $groupsClaim);
        if ($groups === [] && $id instanceof \stdClass) {
            $groups = $this->groups($id, $groupsClaim);
        }

        $clubToken = $id instanceof \stdClass ? $id : $access;

        return new MemberUser(
            $sub,
            $this->name($access, $id, $sub),
            $groups,
            $this->picture($access, $id),
            $this->clubs($clubToken),
            $this->spondId($clubToken),
        );
    }

    private function spondId(\stdClass $token): ?string
    {
        return MemberUser::normalizeSpondId($token->{'https://historic-winterthur.ch/spond_id'} ?? null);
    }

    /** @return list<string> */
    private function clubs(\stdClass $token): array
    {
        $claim = $token->{'https://historic-winterthur.ch/clubs'} ?? null;
        if (is_string($claim)) {
            $claim = [$claim];
        }
        if (!is_array($claim)) {
            return [];
        }

        return MemberUser::normalizeClubs($claim);
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

    private function picture(\stdClass $access, ?\stdClass $id): ?string
    {
        foreach ([$id, $access] as $token) {
            if (!$token instanceof \stdClass) {
                continue;
            }
            $url = MemberUser::normalizePicture($token->picture ?? null);
            if ($url !== null) {
                return $url;
            }
        }
        foreach ([$id, $access] as $token) {
            if (!$token instanceof \stdClass) {
                continue;
            }
            $url = MemberUser::normalizePicture($token->{'https://historic-winterthur.ch/picture'} ?? null);
            if ($url !== null) {
                return $url;
            }
        }
        foreach ([$id, $access] as $token) {
            if (!$token instanceof \stdClass) {
                continue;
            }
            $url = $this->metadataPicture($token);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function metadataPicture(\stdClass $token): ?string
    {
        $meta = $token->app_metadata ?? null;
        if (!$meta instanceof \stdClass) {
            return null;
        }
        $club = $meta->historic_winterthur ?? null;
        if ($club instanceof \stdClass) {
            foreach (['picture', 'profilbild', 'avatar', 'profile_picture'] as $key) {
                $url = MemberUser::normalizePicture($club->{$key} ?? null);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return MemberUser::normalizePicture($meta->picture ?? null);
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
