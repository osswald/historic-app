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
            'category' => null,
            'orgGroup' => null,
            'progress' => ['done' => 1, 'total' => 4, 'percent' => 25],
            'addressable' => true,
        ]], $lists);
    }

    public function testTemplatesExposeTheTaskCount(): void
    {
        self::assertSame([
            ['id' => 'tpl-1', 'name' => 'Depotvorlage', 'category' => null, 'orgGroup' => null, 'taskCount' => 3, 'addressable' => true],
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

    public function testATemplateKeepsTasksOnlyWhenTheApiSendsThem(): void
    {
        $without = TasksMapper::template([
            'id' => 'tpl-1',
            'name' => 'Depotvorlage',
            'description' => 'Vor der Fahrt',
            'task_count' => 3,
        ]);
        self::assertFalse($without['tasksIncluded']);
        self::assertSame([], $without['tasks']);
        self::assertSame('Vor der Fahrt', $without['description']);
        self::assertSame(3, $without['taskCount']);

        $with = TasksMapper::template([
            'id' => 'tpl-1',
            'name' => 'Depotvorlage',
            'tasks' => [
                ['id' => 'task-1', 'title' => 'Wagen prüfen', 'description' => 'Licht', 'status' => 'todo'],
            ],
        ]);
        self::assertTrue($with['tasksIncluded']);
        self::assertSame('Wagen prüfen', $with['tasks'][0]['title']);
        self::assertSame('Licht', $with['tasks'][0]['details']);
        self::assertSame('Offen', $with['tasks'][0]['statusLabel']);
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
        self::assertNull($list['category']);
        self::assertNull($list['orgGroup']);
        self::assertNull($list['tasks'][0]['taskList']);
    }

    public function testEmbeddedObjectsKeepTheNameAndLeaveAMissingObjectBlank(): void
    {
        $categoryId = '6b1c1d2e-3f4a-4b5c-8d9e-0a1b2c3d4e5f';
        $groupId = '7c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
        $listId = '8d3e4f50-6a7b-4c8d-9e0f-1a2b3c4d5e6f';
        $category = ['id' => $categoryId, 'name' => 'Technik', 'color' => '#345749'];
        $group = ['id' => $groupId, 'name' => 'Depot'];

        $lists = TasksMapper::taskLists([[
            'id' => $listId,
            'name' => 'Wagenliste',
            'category' => $category,
            'org_group' => $group,
            'category_id' => $categoryId,
            'org_group_id' => $groupId,
        ]]);

        self::assertSame([
            'id' => $categoryId,
            'name' => 'Technik',
            'color' => '#345749',
        ], $lists[0]['category']);
        self::assertSame(['id' => $groupId, 'name' => 'Depot'], $lists[0]['orgGroup']);
        self::assertSame('Technik', $lists[0]['category']['name']);
        self::assertNotSame($categoryId, $lists[0]['category']['name']);
        self::assertArrayNotHasKey('categoryId', $lists[0]);
        self::assertArrayNotHasKey('orgGroupId', $lists[0]);

        $personal = TasksMapper::taskLists([[
            'id' => 'list-personal',
            'name' => 'Privat',
            'category' => null,
            'org_group' => null,
        ]]);
        self::assertNull($personal[0]['category']);
        self::assertNull($personal[0]['orgGroup']);

        $bareId = TasksMapper::taskLists([[
            'id' => 'list-bare-id',
            'name' => 'Nur Kennung',
            'category' => $categoryId,
            'org_group' => 'null',
        ]]);
        self::assertNull($bareId[0]['category']);
        self::assertNull($bareId[0]['orgGroup']);

        $templates = TasksMapper::templates([['data' => [
            'id' => 'tpl-1',
            'name' => 'Depotvorlage',
            'task_count' => 2,
            'category' => $category,
            'org_group' => null,
        ]]]);
        self::assertSame('Technik', $templates[0]['category']['name']);
        self::assertNull($templates[0]['orgGroup']);

        $template = TasksMapper::template(['data' => [
            'id' => 'tpl-1',
            'name' => 'Depotvorlage',
            'category' => ['id' => $categoryId, 'name' => 'Technik', 'color' => 'rot'],
            'org_group' => $group,
        ]]);
        self::assertSame('Technik', $template['category']['name']);
        self::assertNull($template['category']['color']);
        self::assertSame('Depot', $template['orgGroup']['name']);

        $detail = TasksMapper::taskList(['data' => [
            'id' => $listId,
            'name' => 'Wagenliste',
            'category' => $category,
            'org_group' => null,
            'tasks' => [[
                'id' => 'task-1',
                'title' => 'Licht prüfen',
                'status' => 'todo',
                'task_list' => ['id' => $listId, 'name' => 'Wagenliste'],
            ]],
        ]]);
        self::assertSame('Technik', $detail['category']['name']);
        self::assertNull($detail['orgGroup']);
        self::assertSame('Wagenliste', $detail['tasks'][0]['taskList']['name']);
        self::assertSame('Offen', $detail['tasks'][0]['statusLabel']);
        self::assertNotSame($listId, $detail['tasks'][0]['taskList']['name']);

        $tasks = TasksMapper::tasks(TasksMapper::collection(['data' => [[
            'id' => 'task-2',
            'title' => 'Bremsen',
            'status' => 'in_progress',
            'task_list' => ['id' => $listId, 'name' => 'Wagenliste'],
        ]]]));
        self::assertSame(['id' => $listId, 'name' => 'Wagenliste'], $tasks[0]['taskList']);
        self::assertSame('In Arbeit', $tasks[0]['statusLabel']);

        $withoutList = TasksMapper::tasks([[
            'id' => 'task-3',
            'title' => 'Ohne Liste',
            'status' => 'done',
            'task_list' => null,
        ]]);
        self::assertNull($withoutList[0]['taskList']);
        self::assertSame('Erledigt', $withoutList[0]['statusLabel']);
    }
}
