<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function __construct(private readonly TaskAccess $taskAccess) {}

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
        $this->taskAccess->ensureViewableTo($request->user(), $task);
        $task->load(['creator:id,name', 'department:id,name']);

        return new TaskResource($task);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $user = $request->user();
        $this->taskAccess->ensureManageableBy($user, $task);
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

    public function destroy(Request $request, Task $task): Response|JsonResponse
    {
        $this->taskAccess->ensureManageableBy($request->user(), $task);

        $deleted = DB::transaction(function () use ($task): bool {
            $task = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if (
                $task->taskAssignments()->exists()
                || $task->histories()->exists()
                || $task->notifications()->exists()
                || $task->comments()->exists()
                || $task->attachments()->exists()
            ) {
                return false;
            }

            $task->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'message' => 'The task cannot be deleted while related records exist.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->noContent();
    }
}
