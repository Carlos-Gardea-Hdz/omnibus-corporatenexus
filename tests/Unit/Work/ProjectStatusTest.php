<?php

declare(strict_types=1);

use App\Domain\Work\Enums\ProjectStatus;

/*
| Pure enum contract for ProjectStatus (CONTRACT §C2). No DB, no __() resolution
| beyond the label() key shape — these are Unit tests. Resolution of the keys to
| real copy lives in the Feature WorkLangResolutionTest. The state-machine graph:
|   planning  => [active, archived]
|   active    => [completed, archived]
|   completed => [active, archived]
|   archived  => [active]
| Every self→self edge and every unlisted edge is illegal.
*/

it('is a backed string enum with exactly planning/active/completed/archived', function (): void {
    expect(ProjectStatus::Planning->value)->toBe('planning')
        ->and(ProjectStatus::Active->value)->toBe('active')
        ->and(ProjectStatus::Completed->value)->toBe('completed')
        ->and(ProjectStatus::Archived->value)->toBe('archived');

    $values = array_map(fn (ProjectStatus $s): string => $s->value, ProjectStatus::cases());
    expect($values)->toBe(['planning', 'active', 'completed', 'archived']);
});

it('allows exactly the legal forward transitions', function (): void {
    expect(ProjectStatus::Planning->canTransitionTo(ProjectStatus::Active))->toBeTrue()
        ->and(ProjectStatus::Planning->canTransitionTo(ProjectStatus::Archived))->toBeTrue()
        ->and(ProjectStatus::Active->canTransitionTo(ProjectStatus::Completed))->toBeTrue()
        ->and(ProjectStatus::Active->canTransitionTo(ProjectStatus::Archived))->toBeTrue()
        ->and(ProjectStatus::Completed->canTransitionTo(ProjectStatus::Active))->toBeTrue()
        ->and(ProjectStatus::Completed->canTransitionTo(ProjectStatus::Archived))->toBeTrue()
        // An archived project may only be re-opened to active.
        ->and(ProjectStatus::Archived->canTransitionTo(ProjectStatus::Active))->toBeTrue();
});

it('forbids every self→self transition', function (ProjectStatus $status): void {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with([
    'planning' => ProjectStatus::Planning,
    'active' => ProjectStatus::Active,
    'completed' => ProjectStatus::Completed,
    'archived' => ProjectStatus::Archived,
]);

it('forbids the illegal edges (falsifiable)', function (ProjectStatus $from, ProjectStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'planning → completed' => [ProjectStatus::Planning, ProjectStatus::Completed],
    'active → planning' => [ProjectStatus::Active, ProjectStatus::Planning],
    'completed → planning' => [ProjectStatus::Completed, ProjectStatus::Planning],
    'archived → completed' => [ProjectStatus::Archived, ProjectStatus::Completed],
    'archived → planning' => [ProjectStatus::Archived, ProjectStatus::Planning],
]);

it('reports archived only for the Archived case', function (): void {
    expect(ProjectStatus::Archived->isArchived())->toBeTrue()
        ->and(ProjectStatus::Planning->isArchived())->toBeFalse()
        ->and(ProjectStatus::Active->isArchived())->toBeFalse()
        ->and(ProjectStatus::Completed->isArchived())->toBeFalse();
});

it('routes label() through the work.project_status.* translation keys', function (ProjectStatus $status): void {
    expect($status->label())->toBe(__('work.project_status.'.$status->value));
})->with([
    ProjectStatus::Planning,
    ProjectStatus::Active,
    ProjectStatus::Completed,
    ProjectStatus::Archived,
]);

it('exposes a non-empty hex color for every case', function (ProjectStatus $status): void {
    expect($status->color())->toBeString()->toStartWith('#');
})->with([
    ProjectStatus::Planning,
    ProjectStatus::Active,
    ProjectStatus::Completed,
    ProjectStatus::Archived,
]);
