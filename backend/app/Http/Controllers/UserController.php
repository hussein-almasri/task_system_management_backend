<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->with(['role', 'department']);

        if ($request->user()->role->name === 'department_manager') {
            $query
                ->whereNotNull('department_id')
                ->where('department_id', $request->user()->department_id)
                ->whereHas('role', fn ($roleQuery) => $roleQuery->where('name', 'employee'));
        }

        return UserResource::collection($query->orderBy('id')->get());
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $validated = $request->validated();
        $validated['password'] = Hash::make($validated['password']);
        $validated['status'] ??= 'active';

        $user = User::create($validated)->load(['role', 'department']);

        return new UserResource($user);
    }

    public function show(Request $request, User $user): UserResource
    {
        $user->load(['role', 'department']);

        if (
            $request->user()->role->name === 'department_manager'
            && (
                $user->role->name !== 'employee'
                || $user->department_id === null
                || (int) $user->department_id !== (int) $request->user()->department_id
            )
        ) {
            abort(404);
        }

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $validated = $request->validated();

        if (array_key_exists('password', $validated)) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->fill($validated);
        $user->save();
        $user->load(['role', 'department']);

        return new UserResource($user);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $user->status = $request->validated('status');
        $user->save();
        $user->load(['role', 'department']);

        return new UserResource($user);
    }
}
