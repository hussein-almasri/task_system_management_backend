<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
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
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['pending', 'in_progress', 'on_hold', 'completed', 'cancelled'])],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ];

        if ($this->user()->role->name === 'admin') {
            $rules['department_id'] = ['required', 'integer', 'exists:department,id'];
        } else {
            $rules['department_id'] = ['sometimes', 'integer', 'exists:department,id'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->user()->role->name === 'department_manager'
                    && array_key_exists('department_id', $this->all())
                    && ! $validator->errors()->has('department_id')
                    && (int) $this->input('department_id') !== (int) $this->user()->department_id
                ) {
                    $validator->errors()->add('department_id', 'Managers can only create tasks in their own department.');
                }
            },
        ];
    }
}
