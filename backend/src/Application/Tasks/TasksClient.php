<?php

declare(strict_types=1);

namespace App\Application\Tasks;

use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TasksClient
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function taskLists(string $base, string $token, string $actingUser): mixed
    {
        return $this->request('GET', $base, $token, '/task-lists', $actingUser, null);
    }

    public function taskList(string $base, string $token, string $actingUser, string $id): mixed
    {
        return $this->request('GET', $base, $token, '/task-lists/'.$this->pathId($id), $actingUser, null);
    }

    /**
     * @param array{name: string, description?: string, category_id?: string, org_group_id?: string, is_personal?: bool, template_id?: string} $fields
     */
    public function createTaskList(string $base, string $token, string $actingUser, array $fields): mixed
    {
        $payload = ['name' => $fields['name']];
        foreach (['description', 'category_id', 'org_group_id', 'template_id'] as $key) {
            if (isset($fields[$key]) && is_string($fields[$key]) && $fields[$key] !== '') {
                $payload[$key] = $fields[$key];
            }
        }
        if (array_key_exists('is_personal', $fields)) {
            $payload['is_personal'] = (bool) $fields['is_personal'];
        }

        return $this->request('POST', $base, $token, '/task-lists', $actingUser, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function templates(string $base, string $token, string $actingUser): mixed
    {
        return $this->request('GET', $base, $token, '/templates', $actingUser, null);
    }

    public function tasksForMember(string $base, string $token, string $actingUser, ?string $status = null): mixed
    {
        $query = ['assignee' => $actingUser];
        if ($status !== null) {
            $query['status'] = $status;
        }

        return $this->request('GET', $base, $token, '/tasks?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $actingUser, null);
    }

    public function changeStatus(string $base, string $token, string $actingUser, string $id, string $status): mixed
    {
        if (!TaskStatus::isKnown($status)) {
            throw new InvalidRequest(['status' => 'Der Status ist ungültig.']);
        }

        return $this->request(
            'PATCH',
            $base,
            $token,
            '/tasks/'.$this->pathId($id).'/status',
            $actingUser,
            json_encode(['status' => $status], JSON_THROW_ON_ERROR),
        );
    }

    private function pathId(string $id): string
    {
        if (!TasksMapper::safeId($id)) {
            throw new InvalidRequest(['id' => 'Die Kennung ist ungültig.']);
        }

        return rawurlencode($id);
    }

    private function request(string $method, string $base, string $token, string $path, string $actingUser, ?string $body): mixed
    {
        $actingUser = trim($actingUser);
        if ($actingUser === '' || strpbrk($actingUser, "\r\n\0") !== false) {
            throw new TasksPersonUnknown();
        }
        $this->guardTestHost($base);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'X-Acting-User' => $actingUser,
        ];
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
        } catch (TasksPersonUnknown $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UpstreamUnavailable('Die Aufgaben-App antwortet gerade nicht.');
        }

        if ($status === 401) {
            throw new TasksRejected();
        }
        if ($status === 403) {
            throw new TasksPersonUnknown();
        }
        if ($status === 404) {
            throw new TasksNotFound();
        }
        if ($status === 400) {
            throw new InvalidRequest([], 'Die Aufgaben-App hat die Eingabe abgelehnt.');
        }
        if ($status < 200 || $status >= 300) {
            throw new UpstreamUnavailable('Die Aufgaben-App antwortet gerade nicht.');
        }

        try {
            $content = $response->getContent(false);
        } catch (\Throwable) {
            throw new UpstreamUnavailable('Die Aufgaben-App antwortet gerade nicht.');
        }
        if (trim($content) === '') {
            return null;
        }
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UpstreamUnavailable('Die Aufgaben-App antwortet gerade nicht.');
        }
    }

    private function guardTestHost(string $base): void
    {
        if ($this->environment !== 'test') {
            return;
        }
        $host = parse_url($base, PHP_URL_HOST);
        if ($host !== 'tasks.test') {
            throw new UpstreamUnavailable('Tests fragen die Aufgaben-App nicht ab.');
        }
    }
}
