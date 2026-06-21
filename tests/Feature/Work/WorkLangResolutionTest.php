<?php

declare(strict_types=1);

use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Enums\TaskStatus;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/*
| Slice 004 (Work) user-facing copy. The server emits the work.* PHP keys
| (enum labels via ProjectStatus/TaskStatus/TaskPriority::label() + the flash
| messages + the exception errors). EVERY key MUST resolve in BOTH locales — a
| missing line returns the raw key, which the assertions catch — and a
| representative key must ship DISTINCT es/en copy (so an English fallback
| masking a missing translation is caught too).
|
| The React i18n dictionary (resources/js/lib/i18n.tsx) mirrors every enum label
| key: the parity test at the bottom reads i18n.tsx and proves every enum value
| key is present there (so the page never renders a raw key), AND the old
| landing.notes_count key is gone, replaced by landing.projects_count /
| landing.open_tasks_count.
*/

/** @var list<string> $workKeys */
$workKeys = [
    // project_status.*
    'work.project_status.planning',
    'work.project_status.active',
    'work.project_status.completed',
    'work.project_status.archived',
    // task_status.*
    'work.task_status.todo',
    'work.task_status.in_progress',
    'work.task_status.done',
    // task_priority.*
    'work.task_priority.low',
    'work.task_priority.medium',
    'work.task_priority.high',
    'work.task_priority.urgent',
    // project flash
    'work.project.created',
    'work.project.updated',
    'work.project.transitioned',
    // task flash
    'work.task.created',
    'work.task.updated',
    'work.task.transitioned',
    'work.task.assigned',
    // errors
    'work.errors.invalid_project_transition',
    'work.errors.invalid_task_transition',
    'work.errors.project_archived',
];

it('resolves every work lang key in English (not the raw key)', function (string $key): void {
    App::setLocale('en');

    expect(Lang::has($key, 'en'))->toBeTrue("missing en translation for {$key}")
        ->and(__($key))->not->toBe($key);
})->with($workKeys);

it('resolves every work lang key in Spanish (not the raw key)', function (string $key): void {
    App::setLocale('es');

    expect(Lang::has($key, 'es'))->toBeTrue("missing es translation for {$key}")
        ->and(__($key))->not->toBe($key);
})->with($workKeys);

it('ships distinct ES/EN copy for a representative work key (no English fallback masking)', function (): void {
    App::setLocale('en');
    $en = __('work.errors.project_archived');

    App::setLocale('es');
    $es = __('work.errors.project_archived');

    expect($es)->not->toBe($en)
        ->and($es)->not->toBe('work.errors.project_archived');
});

it('routes every enum label through a resolvable work.* key in both locales', function (): void {
    foreach (['en', 'es'] as $locale) {
        App::setLocale($locale);

        foreach (ProjectStatus::cases() as $status) {
            expect($status->label())->not->toBe('work.project_status.'.$status->value);
        }
        foreach (TaskStatus::cases() as $status) {
            expect($status->label())->not->toBe('work.task_status.'.$status->value);
        }
        foreach (TaskPriority::cases() as $priority) {
            expect($priority->label())->not->toBe('work.task_priority.'.$priority->value);
        }
    }
});

/*
| Frontend parity: the React dictionary must carry every enum label key (so the
| board/list never renders a raw key), and must NOT keep the retired
| landing.notes_count — it is replaced by landing.projects_count /
| landing.open_tasks_count.
*/
it('mirrors every enum label key in the React i18n dictionary and replaces notes_count', function (): void {
    $i18n = (string) file_get_contents(base_path('resources/js/lib/i18n.tsx'));

    /** @var list<string> $enumKeys */
    $enumKeys = [];
    foreach (ProjectStatus::cases() as $status) {
        $enumKeys[] = 'work.project_status.'.$status->value;
    }
    foreach (TaskStatus::cases() as $status) {
        $enumKeys[] = 'work.task_status.'.$status->value;
    }
    foreach (TaskPriority::cases() as $priority) {
        $enumKeys[] = 'work.task_priority.'.$priority->value;
    }

    // NOTE: Pest's toContain() is variadic — every argument is treated as a
    // needle (there is no message parameter), so each key is asserted on its own.
    foreach ($enumKeys as $key) {
        expect($i18n)->toContain("'".$key."'");
    }

    // The retired Note landing key is gone; the new summary keys are present.
    expect($i18n)->not->toContain('landing.notes_count')
        ->and($i18n)->toContain('landing.projects_count')
        ->and($i18n)->toContain('landing.open_tasks_count');
});

/*
| Stronger parity: SET-EQUALITY of the enum-label keys. The lenient toContain()
| test above proves every enum key is PRESENT, but it can't catch DRIFT — a stray
| `work.task_status.*` key in i18n.tsx with no matching enum case, a key present
| with only one locale, or (after a future toContain edit) a missing key. This
| test derives the canonical SET from the enum cases() and asserts i18n.tsx
| carries EXACTLY that set under each family prefix, each with BOTH es + en copy.
*/
it('keeps the React i18n enum-label keys in exact set-parity with the enums (both locales)', function (): void {
    $i18n = (string) file_get_contents(base_path('resources/js/lib/i18n.tsx'));

    // Canonical set from the enums — the single source of truth.
    /** @var list<string> $expected */
    $expected = [];
    foreach (ProjectStatus::cases() as $status) {
        $expected[] = 'work.project_status.'.$status->value;
    }
    foreach (TaskStatus::cases() as $status) {
        $expected[] = 'work.task_status.'.$status->value;
    }
    foreach (TaskPriority::cases() as $priority) {
        $expected[] = 'work.task_priority.'.$priority->value;
    }
    sort($expected);

    // Parse every entry whose key is under one of the three enum families, AND
    // capture that it ships both locales: `'key': { es: '…', en: '…' }`.
    $pattern = "/'(work\\.(?:project_status|task_status|task_priority)\\.[a-z_]+)'\\s*:\\s*\\{[^}]*\\bes\\s*:[^}]*\\ben\\s*:[^}]*\\}/";
    preg_match_all($pattern, $i18n, $matches);

    /** @var list<string> $presentWithBothLocales */
    $presentWithBothLocales = array_values(array_unique($matches[1]));
    sort($presentWithBothLocales);

    // Exact set-equality: no missing key, no orphan key, every one bilingual.
    expect($presentWithBothLocales)->toBe($expected);
});
