<?php

declare(strict_types=1);

namespace App\Application\Tasks;

use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use App\Security\MemberUser;

final class ShowTasks
{
    public function __construct(
        private TasksCredentials $credentials,
        private TasksClient $client,
    ) {
    }

    /** @return array<string, mixed> */
    public function lists(MemberUser $member): array
    {
        return $this->read($member, function (string $base, string $token, string $sub): array {
            return TasksMapper::taskLists(TasksMapper::collection($this->client->taskLists($base, $token, $sub)));
        }, 'lists');
    }

    /** @return array<string, mixed> */
    public function list(MemberUser $member, string $id): array
    {
        return $this->read($member, function (string $base, string $token, string $sub) use ($id): array {
            $data = $this->client->taskList($base, $token, $sub, $id);
            if (!is_array($data) || array_is_list($data)) {
                throw new UpstreamUnavailable('Die Aufgaben-App hat nicht mit einer Liste geantwortet.');
            }

            return TasksMapper::taskList($data);
        }, 'list');
    }

    /** @return array<string, mixed> */
    public function templates(MemberUser $member): array
    {
        return $this->read($member, function (string $base, string $token, string $sub): array {
            return TasksMapper::templates(TasksMapper::collection($this->client->templates($base, $token, $sub)));
        }, 'templates');
    }

    /** @return array<string, mixed> */
    public function template(MemberUser $member, string $id): array
    {
        if (!TasksMapper::safeId($id)) {
            throw new InvalidRequest(['id' => 'Die Kennung ist ungültig.']);
        }

        return $this->read($member, function (string $base, string $token, string $sub) use ($id): array {
            foreach (TasksMapper::collection($this->client->templates($base, $token, $sub)) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $template = TasksMapper::template($row);
                if ($template['id'] === $id) {
                    return $template;
                }
            }

            throw new TasksNotFound();
        }, 'template');
    }

    /** @return array<string, mixed> */
    public function tasks(MemberUser $member, ?string $status): array
    {
        if ($status !== null && !TaskStatus::isKnown($status)) {
            throw new InvalidRequest(['status' => 'Der Status ist ungültig.']);
        }

        return $this->read($member, function (string $base, string $token, string $sub) use ($status): array {
            return TasksMapper::tasks(TasksMapper::collection($this->client->tasksForMember($base, $token, $sub, $status)));
        }, 'tasks');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function create(MemberUser $member, array $fields): array
    {
        $name = $fields['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new InvalidRequest(['name' => 'Der Name fehlt.']);
        }
        $payload = ['name' => trim($name)];
        foreach (['description', 'category_id', 'org_group_id', 'template_id'] as $key) {
            if (!array_key_exists($key, $fields) || $fields[$key] === null || $fields[$key] === '') {
                continue;
            }
            if (!is_string($fields[$key])) {
                throw new InvalidRequest([$key => 'Das Feld ist ungültig.']);
            }
            $value = trim($fields[$key]);
            if ($value === '') {
                continue;
            }
            if ($key !== 'description' && !TasksMapper::safeId($value)) {
                throw new InvalidRequest([$key => 'Die Kennung ist ungültig.']);
            }
            $payload[$key] = $key === 'description' ? $this->limit($value) : $value;
        }
        if (array_key_exists('is_personal', $fields)) {
            if (!is_bool($fields['is_personal'])) {
                throw new InvalidRequest(['is_personal' => 'Das Feld ist ungültig.']);
            }
            $payload['is_personal'] = $fields['is_personal'];
        }
        $payload['name'] = $this->limit($payload['name']);
        [$base, $token, $sub] = $this->connection($member);
        $created = $this->client->createTaskList($base, $token, $sub, $payload);
        $id = is_array($created) ? TasksMapper::taskList($created)['id'] : null;

        return ['id' => is_string($id) ? $id : null];
    }

    public function changeStatus(MemberUser $member, string $id, string $status): void
    {
        if (!TaskStatus::isKnown($status)) {
            throw new InvalidRequest(['status' => 'Der Status ist ungültig.']);
        }
        if (!TasksMapper::safeId($id)) {
            throw new InvalidRequest(['id' => 'Die Kennung ist ungültig.']);
        }
        [$base, $token, $sub] = $this->connection($member);
        $this->client->changeStatus($base, $token, $sub, $id, $status);
    }

    /**
     * @param callable(string, string, string): array<string, mixed>|callable(string, string, string): list<array<string, mixed>> $load
     * @return array<string, mixed>
     */
    private function read(MemberUser $member, callable $load, string $key): array
    {
        try {
            [$base, $token, $sub] = $this->connection($member);
            $value = $load($base, $token, $sub);
        } catch (TasksNotConfigured $exception) {
            return $this->emptyRead($key, false, $exception->missing, $exception->getMessage());
        } catch (TasksBaseInvalid $exception) {
            return $this->emptyRead($key, false, ['TASKS_API_BASE'], $exception->getMessage());
        } catch (TasksRejected|TasksPersonUnknown|TasksNotFound|UpstreamUnavailable $exception) {
            return $this->emptyRead($key, true, [], $exception->getMessage());
        }

        return [
            'configured' => true,
            'missing' => [],
            'notice' => null,
            $key => $value,
        ];
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function connection(MemberUser $member): array
    {
        $sub = trim($member->getUserIdentifier());
        if ($sub === '' || strpbrk($sub, "\r\n\0") !== false) {
            throw new TasksPersonUnknown();
        }
        $secrets = $this->credentials->resolve($this->credentials->fromProcess());

        return [$secrets['base'], $secrets['token'], $sub];
    }

    /**
     * @param list<string> $missing
     * @return array<string, mixed>
     */
    private function emptyRead(string $key, bool $configured, array $missing, string $notice): array
    {
        return [
            'configured' => $configured,
            'missing' => $missing,
            'notice' => $notice,
            $key => $key === 'list' || $key === 'template' ? null : [],
        ];
    }

    private function limit(string $value): string
    {
        return strlen($value) > 500 ? substr($value, 0, 500) : $value;
    }
}
