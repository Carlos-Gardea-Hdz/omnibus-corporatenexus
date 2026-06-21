<?php

declare(strict_types=1);

namespace App\Domain\Work\Exceptions;

use RuntimeException;

/**
 * A task mutation (create/update/transition/assign) was attempted on a task of
 * an ARCHIVED project (slice 004). An archived project is read-only for its
 * tasks. Thrown by the task Actions BEFORE any write. Carries a translated
 * message so the HTTP layer renders a 302 + flash error, never a 500. The Work
 * domain stays free of Illuminate\Http.
 */
final class ProjectArchivedException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('work.errors.project_archived'));
    }
}
