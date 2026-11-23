<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends UserManagementRequest
{
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:department,id'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $user */
                $user = $this->route('user');
                $input = $this->all();

                $this->validateRoleDepartment(
                    $validator,
                    $input['role_id'] ?? $user->role_id,
                    array_key_exists('department_id', $input)
                        ? $input['department_id']
                        : $user->department_id,
                );

                if (! array_intersect(['name', 'email', 'password', 'role_id', 'department_id', 'status'], array_keys($input))) {
                    $validator->errors()->add('user', 'At least one user field must be provided.');
                }
            },
        ];
    }
}
