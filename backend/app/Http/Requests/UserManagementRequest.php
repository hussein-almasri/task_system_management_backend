<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class UserManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->name === 'admin';
    }

    protected function validateRoleDepartment(
        Validator $validator,
        mixed $roleId,
        mixed $departmentId,
    ): void {
        if ($validator->errors()->has('role_id') || $validator->errors()->has('department_id')) {
            return;
        }

        $role = Role::query()->find($roleId);

        if (! $role || ! in_array($role->name, ['admin', 'department_manager', 'employee'], true)) {
            $validator->errors()->add('role_id', 'The selected role is not supported.');

            return;
        }

        if ($role->name !== 'admin' && ($departmentId === null || $departmentId === '')) {
            $validator->errors()->add('department_id', 'A department is required for this role.');
        }
    }
}
