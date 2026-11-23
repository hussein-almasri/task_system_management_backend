<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Task::query()->with(['creator:id,name', 'department:id,name']);

        if ($user->role->name === 'department_manager') {
            $query
                ->whereNotNull('department_id')
                ->where('department_id', $user->department_id);
        } elseif ($user->role->name === 'employee') {
            $query
                ->whereNotNull('department_id')
                ->where('department_id', $user->department_id)
                ->whereHas('taskAssignments', fn ($assignments) => $assignments->where('user_id', $user->id));
        }

        return TaskResource::collection($query->orderBy('id')->get());
    }

    public function store(StoreTaskRequest $request): TaskResource
    {
        $validated = $request->validated();
        $user = $request->user();
        $task = new Task;
        $task->title = $validated['title'];
        $task->description = $validated['description'];
        $task->created_by = $user->id;
        $task->department_id = $user->role->name === 'department_manager'
            ? $user->department_id
            : $validated['department_id'];

        foreach (['status', 'priority', 'due_date'] as $field) {
            if (array_key_exists($field, $validated)) {
                $task->{$field} = $validated[$field];
            }
        }

        $task->save();
        $task->refresh()->load(['creator:id,name', 'department:id,name']);

        return new TaskResource($task);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->ensureVisibleTo($request->user(), $task);
        $task->load(['creator:id,name', 'department:id,name']);

        return new TaskResource($task);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $user = $request->user();
        $this->ensureManagerOwnsTask($user, $task);
        $validated = $request->validated();

        foreach (['title', 'description', 'status', 'priority', 'due_date'] as $field) {
            if (array_key_exists($field, $validated)) {
                $task->{$field} = $validated[$field];
            }
        }

        if ($user->role->name === 'admin' && array_key_exists('department_id', $validated)) {
            $task->department_id = $validated['department_id'];
        }

        $task->save();
        $task->load(['creator:id,name', 'department:id,name']);

        return new TaskResource($task);
    }

    public function destroy(Request $request, Task $task): Response
    {
        $this->ensureManagerOwnsTask($request->user(), $task);
        $task->delete();

        return response()->noContent();
    }

    private function ensureVisibleTo(User $user, Task $task): void
    {
        $this->ensureManagerOwnsTask($user, $task);

        if ($user->role->name === 'employee') {
            if (
                $user->department_id === null
                || (int) $task->department_id !== (int) $user->department_id
                || ! $task->taskAssignments()->where('user_id', $user->id)->exists()
            ) {
                abort(404);
            }
        }
    }

    private function ensureManagerOwnsTask(User $user, Task $task): void
    {
        if (
            $user->role->name === 'department_manager'
            && (
                $user->department_id === null
                || (int) $task->department_id !== (int) $user->department_id
            )
        ) {
            abort(404);
        }
    }
}