<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Application\UpstreamUnavailable;
use App\Infrastructure\Station\OevTrainStopSearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OevTrainStopSearchTest extends TestCase
{
    public function testMapsNameAndKeepsTheOneCharacterAbbreviation(): void
    {
        $body = json_encode([
            'total_count' => 1,
            'results' => [[
                'number' => '8506000',
                'designationofficial' => 'Winterthur',
                'abbreviation' => 'W',
                'sloid' => 'ch:1:sloid:6000',
            ]],
        ], JSON_THROW_ON_ERROR);
        $calls = 0;
        $queries = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($body, &$calls, &$queries): MockResponse {
            ++$calls;
            $queries[] = $options['query'] ?? null;
            self::assertSame('GET', $method);
            self::assertStringContainsString('stop-points-today/records', $url);

            return new MockResponse($body);
        });
        $search = new OevTrainStopSearch($http, new ArrayAdapter());

        $stations = $search->search('Winterthur');
        self::assertSame([[
            'id' => 'ch:1:sloid:6000',
            'didokCode' => '8506000',
            'name' => 'Winterthur',
            'abbreviation' => 'W',
        ]], $stations);
        self::assertSame($stations, $search->search('Winterthur'));
        self::assertSame([[
            'id' => 'ch:1:sloid:6000',
            'didokCode' => '8506000',
            'name' => 'Winterthur',
            'abbreviation' => 'W',
        ]], $search->search('W'));
        self::assertSame([], $search->search('  '));
        self::assertSame(2, $calls);
        self::assertSame('GET', 'GET');
        self::assertSame([
            'limit' => 20,
            'refine' => 'verkehrsmittel:"Zug"',
            'where' => 'search(designationofficial, "Winterthur") OR search(abbreviation, "Winterthur") OR abbreviation = "WINTERTHUR"',
            'select' => 'number,designationofficial,abbreviation,sloid',
        ], $queries[0]);
        self::assertSame(
            'search(designationofficial, "W") OR search(abbreviation, "W") OR abbreviation = "W"',
            $queries[1]['where'] ?? null,
        );
    }

    public function testUpstreamFailureStaysAGermanError(): void
    {
        $search = new OevTrainStopSearch(
            new MockHttpClient(new MockResponse('', ['http_code' => 503])),
            new ArrayAdapter(),
        );

        $this->expectException(UpstreamUnavailable::class);
        $this->expectExceptionMessage('Die Bahnhofsuche ist gerade nicht erreichbar.');
        $search->search('Winterthur');
    }
}
