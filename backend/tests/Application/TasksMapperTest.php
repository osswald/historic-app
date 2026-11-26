<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Tasks\TasksMapper;
use App\Application\UpstreamUnavailable;
use PHPUnit\Framework\TestCase;

final class TasksMapperTest extends TestCase
{
    public function testListsKeepOnlyTheDocumentedFields(): void
    {
        $lists = TasksMapper::taskLists([[
            'id' => 'list-1',
            'name' => 'Depot',
            'title' => 'nicht verwenden',
            'progress' => ['done' => 1, 'total' => 4, 'percent' => 25],
            'secret' => 'hidden',
        ]]);

        self::assertSame([[
            'id' => 'list-1',
            'name' => 'Depot',
            'progress' => ['done' => 1, 'total' => 4, 'percent' => 25],
            'addressable' => true,
        ]], $lists);
    }

    public function testTemplatesExposeTheTaskCount(): void
    {
        self::assertSame([
            ['id' => 'tpl-1', 'name' => 'Depotvorlage', 'taskCount' => 3, 'addressable' => true],
        ], TasksMapper::templates([['id' => 'tpl-1', 'task_count' => 3, 'name' => 'Depotvorlage', 'description' => 'nicht in der Liste']]));
    }

    public function testTasksUseTheThreeStatuses(): void
    {
        $tasks = TasksMapper::tasks([
            ['id' => 'task-1', 'status' => 'todo', 'title' => 'Wagen prüfen', 'description' => 'Bremsen und Licht'],
            ['id' => 'task-2', 'status' => 'in_progress', 'title' => 'Nur der Titel'],
            ['id' => 'task-3', 'status' => 'done', 'name' => 'Aus dem Namen', 'details' => 'Aus dem Detailfeld'],
        ]);

        self::assertSame('Offen', $tasks[0]['statusLabel']);
        self::assertSame('In Arbeit', $tasks[1]['statusLabel']);
        self::assertSame('Erledigt', $tasks[2]['statusLabel']);
        self::assertSame('Wagen prüfen', $tasks[0]['title']);
        self::assertSame('Bremsen und Licht', $tasks[0]['details']);
        self::assertSame('Nur der Titel', $tasks[1]['title']);
        self::assertNull($tasks[1]['details']);
        self::assertSame('Aus dem Namen', $tasks[2]['title']);
        self::assertSame('Aus dem Detailfeld', $tasks[2]['details']);
        self::assertNotSame($tasks[0]['id'], $tasks[0]['title']);
    }

    public function testAWrappedPayloadIsNotTreatedAsAList(): void
    {
        $this->expectException(UpstreamUnavailable::class);
        TasksMapper::collection(['tasks' => []]);
    }

    public function testTheDataEnvelopeIsUnwrapped(): void
    {
        $tasks = TasksMapper::collection(['data' => [
            ['id' => 'task-1', 'status' => 'todo'],
        ]]);
        $list = TasksMapper::taskList(['data' => [
            'id' => 'list-1',
            'name' => 'Depot',
            'tasks' => [['id' => 'task-1', 'status' => 'done']],
        ]]);

        self::assertSame('task-1', TasksMapper::tasks($tasks)[0]['id']);
        self::assertSame('list-1', $list['id']);
        self::assertSame('task-1', $list['tasks'][0]['id']);
    }
}
