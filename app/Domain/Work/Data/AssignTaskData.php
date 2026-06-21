<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Assign-task payload (slice 004). `assigned_to` null = unassign. The member
 * guard is `exists:users,id` resolving in tenant context — a task CANNOT be
 * assigned to a user id absent from THIS tenant's DB (a non-member id fails with
 * a 302 + field error, never a 422/500). Spatie Data is the single source of
 * truth + generated TS type.
 */
#[TypeScript]
final class AssignTaskData extends Data
{
    public function __construct(
        public ?int $assigned_to = null,
    ) {}

    /**
     * @return array<string, array<int, Exists|string>>
     */
    public static function rules(): array
    {
        return [
            'assigned_to' => ['nullable', Rule::exists('users', 'id')],
        ];
    }
}
