<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Helfer\HelferClient;
use App\Application\Helfer\HelferNotFound;
use App\Application\Helfer\HelferPersonUnknown;
use App\Application\Helfer\HelferRejected;
use App\Application\UpstreamUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HelferClientTest extends TestCase
{
    public function testShiftReadsUseOnlyTheBearerKey(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['headers']];

            return new MockResponse('[]');
        });

        $client->shifts('https://helfer.test/api/public/v1', 'test-token', 'ext:11111111-1111-4111-8111-111111111111');

        self::assertSame('GET', $seen[0]);
        self::assertSame('https://helfer.test/api/public/v1/events/ext:11111111-1111-4111-8111-111111111111/shifts', $seen[1]);
        self::assertContains('Authorization: Bearer test-token', $seen[2]);
        self::assertNotContains('X-Acting-User: auth0|ada', $seen[2]);
        self::assertNotContains('X-Acting-User: vera@example.test', $seen[2]);
    }

    public function testPersonalReadsSendTheClubEmailForBothStatuses(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['headers']];

            return new MockResponse('[]');
        });

        $client->myAssignments('https://helfer.test/api/public/v1', 'test-token', 'vera@example.test', 'zugeteilt');
        $client->myAssignments('https://helfer.test/api/public/v1', 'test-token', 'vera@example.test', 'gemeldet');
        $client->myAssignment('https://helfer.test/api/public/v1', 'test-token', 'vera@example.test', 'ext:11111111-1111-4111-8111-111111111111');

        self::assertSame('https://helfer.test/api/public/v1/assignments/me?status=zugeteilt', $seen[0][1]);
        self::assertSame('https://helfer.test/api/public/v1/assignments/me?status=gemeldet', $seen[1][1]);
        self::assertContains('X-Acting-User: vera@example.test', $seen[0][2]);
        self::assertContains('X-Acting-User: vera@example.test', $seen[1][2]);
        self::assertStringContainsString('/my-assignment', $seen[2][1]);
        self::assertContains('X-Acting-User: vera@example.test', $seen[2][2]);
        self::assertNotContains('X-Acting-User: auth0|ada', $seen[0][2]);
    }

    public function testUnknownPersonNotFoundIsNotAMissingEvent(): void
    {
        $client = $this->client(fn (): MockResponse => new MockResponse(
            '{"error":"Keine eindeutige Helferperson für X-Acting-User"}',
            ['http_code' => 404],
        ));

        try {
            $client->myAssignments('https://helfer.test/api/public/v1', 'test-token', 'vera@example.test', 'zugeteilt');
            self::fail('An unknown helper should be reported.');
        } catch (HelferPersonUnknown $exception) {
            self::assertSame('Diese Person ist in der Helferplanung kein aktiver Helfer.', $exception->getMessage());
            self::assertStringNotContainsString('nicht vorhanden', $exception->getMessage());
            self::assertStringNotContainsString('vera@example.test', $exception->getMessage());
        }

        $missing = $this->client(fn (): MockResponse => new MockResponse('{"error":"missing"}', ['http_code' => 404]));
        try {
            $missing->event('https://helfer.test/api/public/v1', 'test-token', '11111111-1111-4111-8111-111111111111');
            self::fail('A missing event should be reported.');
        } catch (HelferNotFound $exception) {
            self::assertSame('Der Anlass ist in der Helferplanung nicht vorhanden.', $exception->getMessage());
        }
    }

    public function testPublishSendsTheEventWithoutAnActingUser(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body'] ?? null, $options['headers']];

            return new MockResponse('{"id":"evt"}', ['http_code' => 201]);
        });

        $client->publishEvent('https://helfer.test/api/v1', 'test-token', [
            'eventId' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Frühlingsfahrt',
            'date' => '2027-04-12',
        ]);

        self::assertSame('POST', $seen[0]);
        self::assertSame('https://helfer.test/api/v1/events', $seen[1]);
        self::assertSame('{"eventId":"11111111-1111-4111-8111-111111111111","title":"Frühlingsfahrt","date":"2027-04-12"}', $seen[2]);
        self::assertNotContains('X-Acting-User: auth0|ada', $seen[3]);
    }

    public function testAWrongKeyAndAnUnknownHelperStayInGermanWithoutTheKey(): void
    {
        $rejected = $this->client(fn (): MockResponse => new MockResponse('{"error":"test-token"}', ['http_code' => 401]));
        $unknown = $this->client(fn (): MockResponse => new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        try {
            $rejected->shifts('https://helfer.test/api/public/v1', 'test-token', 'ext:11111111-1111-4111-8111-111111111111');
            self::fail('A wrong key should be reported.');
        } catch (HelferRejected $exception) {
            self::assertStringNotContainsString('test-token', $exception->getMessage());
        }
        try {
            $unknown->myAssignments('https://helfer.test/api/public/v1', 'test-token', 'vera@example.test', 'gemeldet');
            self::fail('An unknown helper should be reported.');
        } catch (HelferPersonUnknown $exception) {
            self::assertSame('Diese Person ist in der Helferplanung kein aktiver Helfer.', $exception->getMessage());
        }
    }

    public function testTestsDoNotCallTheRealHelferHost(): void
    {
        $client = $this->client(function (): MockResponse {
            self::fail('The real host must not be called.');
        });

        $this->expectException(UpstreamUnavailable::class);
        $client->shifts('https://helfer.historic-winterthur.ch/api/public/v1', 'test-token', 'ext:11111111-1111-4111-8111-111111111111');
    }

    private function client(callable $factory): HelferClient
    {
        return new HelferClient(new MockHttpClient($factory), 'test');
    }
}
