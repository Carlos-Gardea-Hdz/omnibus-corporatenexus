<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Work\Actions\AssignTask;
use App\Domain\Work\Actions\CreateTask;
use App\Domain\Work\Actions\TransitionTask;
use App\Domain\Work\Actions\UpdateTask;
use App\Domain\Work\Data\AssignTaskData;
use App\Domain\Work\Data\CreateTaskData;
use App\Domain\Work\Data\TransitionTaskData;
use App\Domain\Work\Data\UpdateTaskData;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Task management inside tenant context (slice-004, CONTRACT §C7). Gated `auth`
 * upstream in routes/tenant.php and inheriting EnsureTenantIsActive. Open to ANY
 * member — no project-manage gate (a member updates/assigns the tasks they work
 * on); only `actor()` presence is asserted. Anemic by law (≤15 lines/method):
 * each mutation hands a validated DTO (resolved via the method signature → web
 * failure is 302 + session errors, never 422) to its Action, which owns the
 * project-archived guard, the task state-machine guard and the assignee-is-a-member
 * guard (each a 302 + flash/field error via bootstrap/app.php, never a 500). The
 * AssignTaskData/CreateTaskData `exists('users','id')` rule runs on the tenant
 * connection, so a task can only be assigned to a member of THIS tenant. {project}
 * and {task} bind on the tenant connection with scoped bindings (the task must
 * belong to the bound project), so a cross-tenant or mismatched id 404s.
 */
final class TaskController extends Controller
{
    public function store(Project $project, CreateTaskData $data, CreateTask $action): RedirectResponse
    {
        $action->handle($project, $data, $this->actor());

        return redirect()->route('tenant.projects.show', $project)->with('success', __('work.task.created'));
    }

    public function update(Project $project, Task $task, UpdateTaskData $data, UpdateTask $action): RedirectResponse
    {
        $this->actor();
        $action->handle($task, $data);

        return redirect()->route('tenant.projects.show', $project)->with('success', __('work.task.updated'));
    }

    public function transition(Project $project, Task $task, TransitionTaskData $data, TransitionTask $action): RedirectResponse
    {
        $this->actor();
        $action->handle($task, $data->status);

        return redirect()->route('tenant.projects.show', $project)->with('success', __('work.task.transitioned'));
    }

    public function assign(Project $project, Task $task, AssignTaskData $data, AssignTask $action): RedirectResponse
    {
        $this->actor();
        $action->handle($task, $data);

        return redirect()->route('tenant.projects.show', $project)->with('success', __('work.task.assigned'));
    }

    /** The authenticated acting member (the `auth` gate guarantees presence). */
    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, HttpResponse::HTTP_FORBIDDEN);

        return $actor;
    }
}
