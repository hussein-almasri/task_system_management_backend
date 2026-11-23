<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignTaskUsersRequest;
use App\Http\Resources\TaskAssignmentResource;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskAssignmentController extends Controller
{
    public function __construct(private readonly TaskAccess $taskAccess) {}

    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->taskAccess->ensureViewableTo($request->user(), $task);

        return TaskAssignmentResource::collection($this->assignedEmployees($task));
    }

    public function store(AssignTaskUsersRequest $request, Task $task): AnonymousResourceCollection
    {
        $user = $request->user();
        $userIds = array_map('intval', $request->validated('user_ids'));
        $responsibleUserId = (int) $request->validated('responsible_user_id');

        $assignments = DB::transaction(function () use ($task, $user, $userIds, $responsibleUserId) {
            $task = Task::query()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $actor = User::query()
                ->with('role:id,name')
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor->status !== 'active') {
                abort(403, 'Your account is inactive.');
            }

            if (! in_array($actor->role?->name, ['admin', 'department_manager'], true)) {
                abort(403, 'You do not have permission to assign task users.');
            }

            $this->taskAccess->ensureManageableBy($actor, $task);

            $selectedUsers = User::query()
                ->with('role:id,name')
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($selectedUsers->count() !== count($userIds)) {
                throw ValidationException::withMessages([
                    'user_ids' => ['One or more selected users no longer exist.'],
                ]);
            }

            foreach ($selectedUsers as $selectedUser) {
                if (
                    $selectedUser->status !== 'active'
                    || $selectedUser->role?->name !== 'employee'
                    || $selectedUser->department_id === null
                ) {
                    throw ValidationException::withMessages([
                        'user_ids' => ['Only active employees with a department can be assigned to tasks.'],
                    ]);
                }

                if (
                    $actor->role->name === 'department_manager'
                    && (int) $selectedUser->department_id !== (int) $task->department_id
                ) {
                    throw ValidationException::withMessages([
                        'user_ids' => ['Managers can only assign employees from their own department.'],
                    ]);
                }
            }

            $existingAssignments = $task->taskAssignments()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            foreach ($existingAssignments as $assignment) {
                $isResponsible = (int) $assignment->user_id === $responsibleUserId;

                if ($assignment->is_responsible !== $isResponsible) {
                    $assignment->is_responsible = $isResponsible;
                    $assignment->save();
                }
            }

            foreach ($userIds as $userId) {
                if ($existingAssignments->has($userId)) {
                    continue;
                }

                $task->taskAssignments()->create([
                    'user_id' => $userId,
                    'is_responsible' => $userId === $responsibleUserId,
                ]);
            }

            return $this->assignedEmployees($task);
        });

        return TaskAssignmentResource::collection($assignments);
    }

    private function assignedEmployees(Task $task): Collection
    {
        return $task->taskAssignments()
            ->whereHas('user.role', fn ($query) => $query->where('name', 'employee'))
            ->whereHas('user.role', fn ($query) => $query->where('name', 'employee'))
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get();
    }
}
