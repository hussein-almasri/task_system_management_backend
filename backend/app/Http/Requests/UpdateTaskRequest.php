<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $role = $user?->role?->name;

        return in_array($role, ['admin', 'department_manager'], true)
            && ($role !== 'department_manager' || $user->department_id !== null);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['pending', 'in_progress', 'on_hold', 'completed', 'cancelled'])],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'department_id' => ['sometimes', 'required', 'integer', 'exists:department,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Task $task */
                $task = $this->route('task');
                $user = $this->user();
                $input = $this->all();

                if (
                    $user->role->name === 'department_manager'
                    && array_key_exists('department_id', $input)
                    && ! $validator->errors()->has('department_id')
                    && (int) $input['department_id'] !== (int) $user->department_id
                ) {
                    $validator->errors()->add('department_id', 'Managers cannot move tasks to another department.');
                }

                if (! array_intersect(['title', 'description', 'status', 'priority', 'due_date', 'department_id'], array_keys($input))) {
                    $validator->errors()->add('task', 'At least one task field must be provided.');
                }
            },
        ];
    }
}
