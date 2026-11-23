<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskAccess
{
    public function ensureViewableTo(User $user, Task $task): void
    {
        if ($user->role->name === 'admin') {
            return;
        }

        if (
            $user->role->name === 'department_manager'
            && $user->department_id !== null
            && (int) $task->department_id === (int) $user->department_id
        ) {
            return;
        }

        if (
            $user->role->name === 'employee'
            && $user->department_id !== null
            && (int) $task->department_id === (int) $user->department_id
            && $task->taskAssignments()->where('user_id', $user->id)->exists()
        ) {
            return;
        }

        abort(404);
    }

    public function ensureManageableBy(User $user, Task $task): void
    {
        if ($user->role->name === 'admin') {
            return;
        }

        if (
            $user->role->name === 'department_manager'
            && $user->department_id !== null
            && (int) $task->department_id === (int) $user->department_id
        ) {
            return;
        }

        abort(404);
    }
}
