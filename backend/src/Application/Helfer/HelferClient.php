<?php

declare(strict_types=1);

namespace App\Application\Helfer;

use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HelferClient
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /** @param array<string, mixed> $fields */
    public function publishEvent(string $base, string $token, array $fields): mixed
    {
        return $this->request('POST', $base, $token, '/events', null, json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function events(string $base, string $token): mixed
    {
        return $this->request('GET', $base, $token, '/events?from=2018-01-01', null, null);
    }

    public function event(string $base, string $token, string $id): mixed
    {
        return $this->request('GET', $base, $token, '/events/'.$this->pathId($id), null, null);
    }

    public function shifts(string $base, string $token, string $eventId): mixed
    {
        return $this->request('GET', $base, $token, '/events/'.$this->pathId($eventId).'/shifts', null, null);
    }

    public function assignments(string $base, string $token, string $shiftId): mixed
    {
        return $this->request('GET', $base, $token, '/shifts/'.$this->pathId($shiftId).'/assignments', null, null);
    }

    public function myAssignment(string $base, string $token, string $actingUser, string $eventId): mixed
    {
        return $this->request('GET', $base, $token, '/events/'.$this->pathId($eventId).'/my-assignment', $actingUser, null);
    }

    public function myAssignments(string $base, string $token, string $actingUser, string $status): mixed
    {
        if ($status !== 'zugeteilt' && $status !== 'gemeldet') {
            throw new InvalidRequest(['status' => 'Der Status ist ungültig.']);
        }

        return $this->request('GET', $base, $token, '/assignments/me?status='.$status, $actingUser, null);
    }

    private function pathId(string $id): string
    {
        if (preg_match('/^ext:[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $id) === 1) {
            return 'ext:'.rawurlencode(substr($id, 4));
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new InvalidRequest(['id' => 'Die Kennung ist ungültig.']);
        }

        return rawurlencode($id);
    }

    private function request(string $method, string $base, string $token, string $path, ?string $actingUser, ?string $body): mixed
    {
        $this->guardTestHost($base);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];
        if ($actingUser !== null) {
            $actingUser = trim($actingUser);
            if ($actingUser === '' || strpbrk($actingUser, "\r\n\0") !== false) {
                throw new HelferPersonUnknown();
            }
            $headers['X-Acting-User'] = $actingUser;
        }
        $options = [
            'headers' => $headers,
            'max_redirects' => 0,
            'timeout' => 8,
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $body;
        }

        try {
            $response = $this->http->request($method, $base.$path, $options);
            $status = $response->getStatusCode();
        } catch (HelferPersonUnknown $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UpstreamUnavailable('Die Helferplanung antwortet gerade nicht.');
        }

        if ($status === 401) {
            throw new HelferRejected();
        }
        if ($status === 403) {
            throw new HelferPersonUnknown();
        }
        if ($status === 404) {
            throw $this->unknownPerson($response) ? new HelferPersonUnknown() : new HelferNotFound();
        }
        if ($status === 400) {
            throw new InvalidRequest([], 'Die Helferplanung hat die Eingabe abgelehnt.');
        }
        if ($status < 200 || $status >= 300) {
            throw new UpstreamUnavailable('Die Helferplanung antwortet gerade nicht.');
        }

        try {
            $content = $response->getContent(false);
        } catch (\Throwable) {
            throw new UpstreamUnavailable('Die Helferplanung antwortet gerade nicht.');
        }
        if (trim($content) === '') {
            return null;
        }
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UpstreamUnavailable('Die Helferplanung antwortet gerade nicht.');
        }
    }

    private function unknownPerson(\Symfony\Contracts\HttpClient\ResponseInterface $response): bool
    {
        try {
            $decoded = json_decode($response->getContent(false), true);
        } catch (\Throwable) {
            return false;
        }
        $message = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return is_string($message) && (str_contains($message, 'Helferperson') || str_contains($message, 'X-Acting-User'));
    }

    private function guardTestHost(string $base): void
    {
        if ($this->environment !== 'test') {
            return;
        }
        $host = parse_url($base, PHP_URL_HOST);
        if ($host !== 'helfer.test') {
            throw new UpstreamUnavailable('Tests fragen die Helferplanung nicht ab.');
        }
    }
}
