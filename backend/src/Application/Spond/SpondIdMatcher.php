<?php

declare(strict_types=1);

namespace App\Application\Spond;

/** Matches export ids to Auth0 app_metadata.historic_winterthur.spond_id. */
final class SpondIdMatcher
{
    public function spondId(mixed $appMetadata): ?string
    {
        $club = $this->value($appMetadata, 'historic_winterthur');
        $id = $this->value($club, 'spond_id');
        if (!is_string($id)) {
            return null;
        }
        $id = trim($id);

        return $id === '' ? null : $id;
    }

    /**
     * Accounts whose Spond id is not in the export are ignored.
     *
     * @param list<string> $exportIds
     * @param list<array{sub: string, appMetadata: mixed}> $accounts
     * @return array<string, string> spond id => Auth0 sub
     */
    public function match(array $exportIds, array $accounts): array
    {
        $allowed = [];
        foreach ($exportIds as $id) {
            $id = trim($id);
            if ($id !== '') {
                $allowed[$id] = true;
            }
        }

        $matched = [];
        foreach ($accounts as $account) {
            $sub = trim($account['sub']);
            $spondId = $this->spondId($account['appMetadata']);
            if ($sub === '' || $spondId === null || !isset($allowed[$spondId]) || isset($matched[$spondId])) {
                continue;
            }
            $matched[$spondId] = $sub;
        }

        return $matched;
    }

    private function value(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }
        if (is_object($source) && isset($source->{$key})) {
            return $source->{$key};
        }

        return null;
    }
}
