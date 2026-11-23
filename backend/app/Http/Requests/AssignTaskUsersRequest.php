<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignTaskUsersRequest extends FormRequest
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
            'user_ids' => ['required', 'array', 'list', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'responsible_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $userIds = $this->input('user_ids');
                $responsibleUserId = $this->input('responsible_user_id');

                if (
                    ! is_array($userIds)
                    || $validator->errors()->has('user_ids')
                    || $validator->errors()->has('responsible_user_id')
                    || ! is_scalar($responsibleUserId)
                    || count(array_filter($userIds, fn ($userId) => ! is_scalar($userId))) > 0
                ) {
                    return;
                }

                if (! in_array((string) $responsibleUserId, array_map('strval', $userIds), true)) {
                    $validator->errors()->add('responsible_user_id', 'The responsible user must be included in user_ids.');
                }
            },
        ];
    }
}
