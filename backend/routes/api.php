
<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TaskAssignmentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Login - does not require authentication
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return $request->user()->load(['role', 'department']);
    });

    Route::middleware('role:admin,department_manager')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
    });

    Route::middleware('role:admin')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->whereNumber('user');

        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::get('/departments/{department}', [DepartmentController::class, 'show'])->whereNumber('department');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department');

        Route::get('/roles', [RoleController::class, 'index']);
    });

    Route::middleware('role:admin,department_manager,employee')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
        Route::get('/tasks/{task}/assignments', [TaskAssignmentController::class, 'index'])->whereNumber('task');
    });

    Route::middleware('role:admin,department_manager')->group(function () {
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task');
        Route::post('/tasks/{task}/assignments', [TaskAssignmentController::class, 'store'])->whereNumber('task');
    });
});
