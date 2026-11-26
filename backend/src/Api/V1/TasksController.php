<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Tasks\EventTaskLists;
use App\Application\Tasks\ShowTasks;
use App\Application\Tasks\TasksBaseInvalid;
use App\Application\Tasks\TasksNotConfigured;
use App\Application\Tasks\TasksNotFound;
use App\Application\Tasks\TasksPersonUnknown;
use App\Application\Tasks\TasksRejected;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TasksController extends ApiController
{
    #[Route('/api/v1/aufgaben/task-lists', name: 'api_tasks_lists', methods: ['GET'])]
    public function lists(ShowTasks $tasks): Response
    {
        return $this->jsonData($tasks->lists($this->member()));
    }

    #[Route('/api/v1/aufgaben/task-lists/{id}', name: 'api_tasks_list', methods: ['GET'])]
    public function list(string $id, ShowTasks $tasks): Response
    {
        return $this->jsonData($tasks->list($this->member(), $id));
    }

    #[Route('/api/v1/aufgaben/templates', name: 'api_tasks_templates', methods: ['GET'])]
    public function templates(ShowTasks $tasks): Response
    {
        return $this->jsonData($tasks->templates($this->member()));
    }

    #[Route('/api/v1/aufgaben/templates/{id}', name: 'api_tasks_template', methods: ['GET'])]
    public function template(string $id, ShowTasks $tasks): Response
    {
        return $this->jsonData($tasks->template($this->member(), $id));
    }

    #[Route('/api/v1/events/{id}/task-lists', name: 'api_event_task_lists', methods: ['GET'])]
    public function eventLists(string $id, EventTaskLists $lists): Response
    {
        return $this->jsonData($lists->forEvent($this->member(), $id));
    }

    #[Route('/api/v1/events/{id}/task-lists', name: 'api_event_task_lists_create', methods: ['POST'])]
    public function eventCreate(string $id, Request $request, EventTaskLists $lists): Response
    {
        try {
            $payload = json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }
        if (!is_array($payload)) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }

        return $this->call(fn (): array => $lists->create($this->member(), $id, $payload), 201);
    }

    #[Route('/api/v1/aufgaben/tasks', name: 'api_tasks_mine', methods: ['GET'])]
    public function tasks(Request $request, ShowTasks $tasks): Response
    {
        $status = $request->query->get('status');

        return $this->jsonData($tasks->tasks($this->member(), is_string($status) && $status !== '' ? $status : null));
    }

    #[Route('/api/v1/aufgaben/task-lists', name: 'api_tasks_create', methods: ['POST'])]
    public function create(Request $request, ShowTasks $tasks): Response
    {
        try {
            $payload = json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }
        if (!is_array($payload)) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }

        return $this->call(fn (): array => $tasks->create($this->member(), $payload), 201);
    }

    #[Route('/api/v1/aufgaben/tasks/{id}/status', name: 'api_tasks_status', methods: ['PATCH'])]
    public function status(string $id, Request $request, ShowTasks $tasks): Response
    {
        try {
            $payload = json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Die Eingaben sind unvollständig.');
        }
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        if (!is_string($status)) {
            throw new HttpException(400, 'Der Status fehlt.');
        }

        return $this->call(function () use ($tasks, $id, $status): array {
            $tasks->changeStatus($this->member(), $id, $status);

            return ['status' => $status];
        });
    }

    /** @param callable(): array<string, mixed> $action */
    private function call(callable $action, int $success = 200): Response
    {
        try {
            return $this->jsonData($action(), $success);
        } catch (TasksNotConfigured|TasksBaseInvalid $exception) {
            throw new HttpException(503, $exception->getMessage());
        } catch (TasksRejected $exception) {
            throw new HttpException(502, $exception->getMessage());
        } catch (TasksPersonUnknown $exception) {
            throw new HttpException(403, $exception->getMessage());
        } catch (TasksNotFound $exception) {
            throw new HttpException(404, $exception->getMessage());
        }
    }
}
