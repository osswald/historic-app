<?php

declare(strict_types=1);

namespace App\Infrastructure\Station;

use App\Application\UpstreamUnavailable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Öffentliche Haltestellenliste, nur Züge.
 * Felder von stop-points-today: number → DiDok (nur intern), designationofficial → Name,
 * abbreviation → Kurzzeichen, sloid → stabile Id.
 * Winterthur hat das einstellige Kürzel «W»; einstellige Suchen und Kürzel bleiben gültig.
 */
final class OevTrainStopSearch
{
    private const ENDPOINT = 'https://data.oev-info.ch/api/explore/v2.1/catalog/datasets/stop-points-today/records';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<array{id: ?string, didokCode: string, name: string, abbreviation: ?string}>
     */
    public function search(string $query): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }
        $term = mb_substr($term, 0, 80);
        $item = $this->cache->getItem('stations.zug.v2.'.hash('sha256', mb_strtolower($term)));
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                /** @var list<array{id: ?string, didokCode: string, name: string, abbreviation: ?string}> $cached */
                return $cached;
            }
        }

        $quoted = $this->escape($term);
        $exactAbbreviation = $this->escape(mb_strtoupper($term, 'UTF-8'));
        try {
            $response = $this->http->request('GET', self::ENDPOINT, [
                'timeout' => 4,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'historic-winterthur',
                ],
                'query' => [
                    'limit' => 20,
                    'refine' => 'verkehrsmittel:"Zug"',
                    'where' => sprintf(
                        'search(designationofficial, "%1$s") OR search(abbreviation, "%1$s") OR abbreviation = "%2$s"',
                        $quoted,
                        $exactAbbreviation,
                    ),
                    'select' => 'number,designationofficial,abbreviation,sloid',
                ],
            ]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (ExceptionInterface) {
            throw new UpstreamUnavailable('Die Bahnhofsuche ist gerade nicht erreichbar.');
        }

        if ($status >= 400 || !isset($payload['results']) || !is_array($payload['results'])) {
            throw new UpstreamUnavailable('Die Bahnhofsuche ist gerade nicht erreichbar.');
        }

        $stations = [];
        foreach ($payload['results'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $mapped = $this->map($record);
            if ($mapped !== null) {
                $stations[] = $mapped;
            }
        }

        $item->set($stations);
        $item->expiresAfter(600);
        $this->cache->save($item);

        return $stations;
    }

    /** @param array<string, mixed> $record
     *  @return array{id: ?string, didokCode: string, name: string, abbreviation: ?string}|null
     */
    private function map(array $record): ?array
    {
        $code = trim((string) ($record['number'] ?? ''));
        $name = trim((string) ($record['designationofficial'] ?? ''));
        $id = trim((string) ($record['sloid'] ?? ''));
        $abbreviation = trim((string) ($record['abbreviation'] ?? ''));
        if ($code === '' || $name === '' || preg_match('/^\d{1,12}$/', $code) !== 1) {
            return null;
        }

        return [
            'id' => $id !== '' ? $id : null,
            'didokCode' => $code,
            'name' => $name,
            'abbreviation' => $abbreviation !== '' ? $abbreviation : null,
        ];
    }

    private function escape(string $term): string
    {
        $term = preg_replace('/[\x00-\x1F]/', '', $term) ?? $term;

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $term);
    }
}
