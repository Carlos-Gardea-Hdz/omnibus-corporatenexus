<?php

declare(strict_types=1);

use App\Domain\Work\Enums\TaskStatus;

/*
| Pure enum contract for TaskStatus (CONTRACT §C2). The state-machine graph:
|   todo        => [in_progress]
|   in_progress => [done, todo]
|   done        => [in_progress]
| Every self→self edge and every unlisted edge is illegal (falsifiable below).
*/

it('is a backed string enum with exactly todo/in_progress/done', function (): void {
    expect(TaskStatus::Todo->value)->toBe('todo')
        ->and(TaskStatus::InProgress->value)->toBe('in_progress')
        ->and(TaskStatus::Done->value)->toBe('done');

    $values = array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::cases());
    expect($values)->toBe(['todo', 'in_progress', 'done']);
});

it('allows exactly the legal transitions', function (): void {
    expect(TaskStatus::Todo->canTransitionTo(TaskStatus::InProgress))->toBeTrue()
        ->and(TaskStatus::InProgress->canTransitionTo(TaskStatus::Done))->toBeTrue()
        ->and(TaskStatus::InProgress->canTransitionTo(TaskStatus::Todo))->toBeTrue()
        ->and(TaskStatus::Done->canTransitionTo(TaskStatus::InProgress))->toBeTrue();
});

it('forbids every self→self transition', function (TaskStatus $status): void {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with([
    'todo' => TaskStatus::Todo,
    'in_progress' => TaskStatus::InProgress,
    'done' => TaskStatus::Done,
]);

it('forbids the illegal edges (falsifiable)', function (TaskStatus $from, TaskStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'todo → done (must pass through in_progress)' => [TaskStatus::Todo, TaskStatus::Done],
    'done → todo' => [TaskStatus::Done, TaskStatus::Todo],
]);

it('routes label() through the work.task_status.* translation keys', function (TaskStatus $status): void {
    expect($status->label())->toBe(__('work.task_status.'.$status->value));
})->with([
    TaskStatus::Todo,
    TaskStatus::InProgress,
    TaskStatus::Done,
]);

it('exposes a non-empty hex color for every case', function (TaskStatus $status): void {
    expect($status->color())->toBeString()->toStartWith('#');
})->with([
    TaskStatus::Todo,
    TaskStatus::InProgress,
    TaskStatus::Done,
]);
