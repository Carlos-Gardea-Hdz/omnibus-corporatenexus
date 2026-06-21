<?php

declare(strict_types=1);

use App\Domain\Work\Enums\TaskPriority;

/*
| Pure enum contract for TaskPriority (CONTRACT §C2). weight() gives a 1–4
| ordering (low < medium < high < urgent); label() points at the
| work.task_priority.* keys; color() is a hex string per case.
*/

it('is a backed string enum with exactly low/medium/high/urgent', function (): void {
    expect(TaskPriority::Low->value)->toBe('low')
        ->and(TaskPriority::Medium->value)->toBe('medium')
        ->and(TaskPriority::High->value)->toBe('high')
        ->and(TaskPriority::Urgent->value)->toBe('urgent');

    $values = array_map(fn (TaskPriority $p): string => $p->value, TaskPriority::cases());
    expect($values)->toBe(['low', 'medium', 'high', 'urgent']);
});

it('exposes a strictly ascending weight ladder (1–4)', function (): void {
    expect(TaskPriority::Low->weight())->toBe(1)
        ->and(TaskPriority::Medium->weight())->toBe(2)
        ->and(TaskPriority::High->weight())->toBe(3)
        ->and(TaskPriority::Urgent->weight())->toBe(4);

    // Strictly ascending — urgent outranks high outranks medium outranks low.
    expect(TaskPriority::Urgent->weight())->toBeGreaterThan(TaskPriority::High->weight())
        ->and(TaskPriority::High->weight())->toBeGreaterThan(TaskPriority::Medium->weight())
        ->and(TaskPriority::Medium->weight())->toBeGreaterThan(TaskPriority::Low->weight());
});

it('routes label() through the work.task_priority.* translation keys', function (TaskPriority $priority): void {
    expect($priority->label())->toBe(__('work.task_priority.'.$priority->value));
})->with([
    TaskPriority::Low,
    TaskPriority::Medium,
    TaskPriority::High,
    TaskPriority::Urgent,
]);

it('exposes a non-empty hex color for every case', function (TaskPriority $priority): void {
    expect($priority->color())->toBeString()->toStartWith('#');
})->with([
    TaskPriority::Low,
    TaskPriority::Medium,
    TaskPriority::High,
    TaskPriority::Urgent,
]);
