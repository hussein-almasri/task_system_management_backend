<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $schema = Schema::connection('sqlite');

        $schema->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $schema->create('department', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('department_id')->nullable();
            $table->timestamps();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('department_id')->references('id')->on('department')->restrictOnDelete()->restrictOnUpdate();
        });

        $schema->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        $schema->create('tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->string('priority', 20)->default('medium');
            $table->dateTime('due_date')->nullable();
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('department_id');
            $table->timestamps();
            $table->index('created_by');
            $table->index('department_id');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('department_id')->references('id')->on('department')->restrictOnDelete()->restrictOnUpdate();
        });

        foreach (['task_history', 'notifications', 'task_comments', 'task_attachments'] as $tableName) {
            $schema->create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('task_id');
                $table->index('task_id');
                $table->foreign('task_id')->references('id')->on('tasks')->restrictOnDelete()->restrictOnUpdate();
            });
        }

        $schema->create('task_assignments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('task_id');
            $table->unsignedInteger('user_id');
            $table->boolean('is_responsible')->default(false);
            $table->timestamp('assigned_at')->nullable()->useCurrent();
            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('task_id')->references('id')->on('tasks')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function test_admin_can_create_a_task_and_created_by_is_derived_from_authentication(): void
    {
        $department = $this->createDepartment('Engineering');
        $otherDepartment = $this->createDepartment('Finance');
        $admin = $this->createUser('admin', null, 'Admin', 'admin@example.test');
        $otherUser = $this->createUser('employee', $otherDepartment, 'Employee', 'employee@example.test');
        Sanctum::actingAs($admin->load('role'));

        $this->postJson('/api/tasks', [
            'title' => 'Prepare release',
            'description' => 'Complete the release checklist.',
            'department_id' => $department->id,
            'created_by' => $otherUser->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.created_by', $admin->id)
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.priority', 'medium')
            ->assertJsonPath('data.creator.id', $admin->id)
            ->assertJsonMissingPath('data.creator.password')
            ->assertJsonMissingPath('data.creator.remember_token');

        $this->assertSame(1, Task::query()->count());
        $this->assertSame($admin->id, Task::query()->firstOrFail()->created_by);
    }

    public function test_admin_can_list_tasks_across_departments_and_view_update_delete_any_task(): void
    {
        $firstDepartment = $this->createDepartment('Engineering');
        $secondDepartment = $this->createDepartment('Finance');
        $admin = $this->createUser('admin', null, 'Admin', 'admin@example.test');
        $creator = $this->createUser('employee', $firstDepartment, 'Employee', 'employee@example.test');
        $firstTask = $this->createTask($creator, $firstDepartment, 'Engineering task');
        $secondTask = $this->createTask($creator, $secondDepartment, 'Finance task');
        Sanctum::actingAs($admin->load('role'));

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/tasks/'.$secondTask->id)
            ->assertOk()
            ->assertJsonPath('data.id', $secondTask->id);

        $this->putJson('/api/tasks/'.$secondTask->id, [
            'title' => 'Updated finance task',
            'department_id' => $firstDepartment->id,
            'created_by' => $admin->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated finance task')
            ->assertJsonPath('data.department_id', $firstDepartment->id)
            ->assertJsonPath('data.created_by', $creator->id);

        $this->deleteJson('/api/tasks/'.$firstTask->id)->assertNoContent();
        $this->assertDatabaseMissing('tasks', ['id' => $firstTask->id]);
    }

    public function test_delete_preserves_tasks_that_have_restrictive_child_records(): void
    {
        $department = $this->createDepartment('Engineering');
        $admin = $this->createUser('admin', null, 'Admin', 'admin@example.test');
        $task = $this->createTask($admin, $department, 'Referenced task');

        foreach (['task_assignments', 'task_history', 'notifications', 'task_comments', 'task_attachments'] as $table) {
            $values = ['task_id' => $task->id];

            if ($table === 'task_assignments') {
                $employee = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
                $values['user_id'] = $employee->id;
                $values['is_responsible'] = false;
            }

            DB::table($table)->insert($values);
        }

        Sanctum::actingAs($admin->load('role'));

        $this->deleteJson('/api/tasks/'.$task->id)
            ->assertConflict()
            ->assertJsonPath('message', 'The task cannot be deleted while related records exist.');

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        foreach (['task_assignments', 'task_history', 'notifications', 'task_comments', 'task_attachments'] as $table) {
            $this->assertDatabaseHas($table, ['task_id' => $task->id]);
        }
    }

    public function test_department_manager_creates_tasks_in_their_department_only(): void
    {
        $ownDepartment = $this->createDepartment('Engineering');
        $otherDepartment = $this->createDepartment('Finance');
        $manager = $this->createUser('department_manager', $ownDepartment, 'Manager', 'manager@example.test');
        Sanctum::actingAs($manager->load('role'));

        $this->postJson('/api/tasks', [
            'title' => 'Own department task',
            'description' => 'A department task.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.department_id', $ownDepartment->id)
            ->assertJsonPath('data.created_by', $manager->id);

        $this->postJson('/api/tasks', [
            'title' => 'Other department task',
            'description' => 'This must be rejected.',
            'department_id' => $otherDepartment->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_department_manager_cannot_view_or_modify_another_departments_task(): void
    {
        $ownDepartment = $this->createDepartment('Engineering');
        $otherDepartment = $this->createDepartment('Finance');
        $manager = $this->createUser('department_manager', $ownDepartment, 'Manager', 'manager@example.test');
        $creator = $this->createUser('employee', $otherDepartment, 'Employee', 'employee@example.test');
        $otherTask = $this->createTask($creator, $otherDepartment, 'Private task');
        Sanctum::actingAs($manager->load('role'));

        $this->getJson('/api/tasks/'.$otherTask->id)->assertNotFound();
        $this->putJson('/api/tasks/'.$otherTask->id, ['title' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/tasks/'.$otherTask->id)->assertNotFound();
        $this->assertDatabaseHas('tasks', ['id' => $otherTask->id, 'title' => 'Private task']);
    }

    public function test_department_manager_cannot_move_an_own_task_to_another_department(): void
    {
        $ownDepartment = $this->createDepartment('Engineering');
        $otherDepartment = $this->createDepartment('Finance');
        $manager = $this->createUser('department_manager', $ownDepartment, 'Manager', 'manager@example.test');
        $task = $this->createTask($manager, $ownDepartment, 'Own task');
        Sanctum::actingAs($manager->load('role'));

        $this->putJson('/api/tasks/'.$task->id, ['department_id' => $otherDepartment->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_employee_only_sees_tasks_assigned_to_them_in_their_department(): void
    {
        $ownDepartment = $this->createDepartment('Engineering');
        $otherDepartment = $this->createDepartment('Finance');
        $employee = $this->createUser('employee', $ownDepartment, 'Employee', 'employee@example.test');
        $creator = $this->createUser('admin', null, 'Admin', 'admin@example.test');
        $assignedTask = $this->createTask($creator, $ownDepartment, 'Assigned task');
        $unassignedTask = $this->createTask($creator, $ownDepartment, 'Unassigned task');
        $otherDepartmentTask = $this->createTask($creator, $otherDepartment, 'Other department task');
        $this->assignTask($assignedTask, $employee);
        $this->assignTask($otherDepartmentTask, $employee);
        Sanctum::actingAs($employee->load('role'));

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedTask->id);

        $this->getJson('/api/tasks/'.$assignedTask->id)->assertOk();
        $this->getJson('/api/tasks/'.$unassignedTask->id)->assertNotFound();
        $this->getJson('/api/tasks/'.$otherDepartmentTask->id)->assertNotFound();
    }

    public function test_employee_cannot_create_update_or_delete_tasks(): void
    {
        $department = $this->createDepartment('Engineering');
        $employee = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        $task = $this->createTask($employee, $department, 'Existing task');
        Sanctum::actingAs($employee->load('role'));

        $this->postJson('/api/tasks', [])->assertForbidden();
        $this->putJson('/api/tasks/'.$task->id, [])->assertForbidden();
        $this->deleteJson('/api/tasks/'.$task->id)->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_unauthenticated_users_receive_401_for_task_endpoints(): void
    {
        $department = $this->createDepartment('Engineering');
        $creator = $this->createUser('admin', null, 'Admin', 'admin@example.test');
        $task = $this->createTask($creator, $department, 'Existing task');

        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->postJson('/api/tasks', [])->assertUnauthorized();
        $this->getJson('/api/tasks/'.$task->id)->assertUnauthorized();
        $this->putJson('/api/tasks/'.$task->id, [])->assertUnauthorized();
        $this->deleteJson('/api/tasks/'.$task->id)->assertUnauthorized();
    }

    public function test_inactive_users_are_blocked_from_task_endpoints(): void
    {
        $department = $this->createDepartment('Engineering');
        $employee = $this->createUser('employee', $department, 'Inactive Employee', 'inactive@example.test');
        $employee->status = 'inactive';
        $employee->save();
        Sanctum::actingAs($employee->load('role'));

        $this->getJson('/api/tasks')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');
    }

    public function test_task_validation_rejects_invalid_values_and_missing_required_description(): void
    {
        $department = $this->createDepartment('Engineering');
        $admin = $this->createUser('admin', null, 'Admin', 'admin@example.test');
        Sanctum::actingAs($admin->load('role'));

        $this->postJson('/api/tasks', $this->validTaskPayload($department, ['priority' => 'critical']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('priority');

        $this->postJson('/api/tasks', $this->validTaskPayload($department, ['status' => 'started']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->postJson('/api/tasks', $this->validTaskPayload($department, ['due_date' => 'not-a-date']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $this->postJson('/api/tasks', $this->validTaskPayload(null, ['department_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->postJson('/api/tasks', [
            'title' => 'Missing description',
            'department_id' => $department->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');
    }

    private function validTaskPayload(?Department $department, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Valid task',
            'description' => 'A valid task description.',
            'department_id' => $department?->id,
        ], $overrides);
    }

    private function createRole(string $name): Role
    {
        return Role::query()->create(['name' => $name]);
    }

    private function createDepartment(string $name): Department
    {
        return Department::query()->create(['name' => $name]);
    }

    private function createUser(string $roleName, ?Department $department, string $name, string $email): User
    {
        $role = Role::query()->where('name', $roleName)->first() ?? $this->createRole($roleName);

        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('fixture-password'),
            'status' => 'active',
            'role_id' => $role->id,
            'department_id' => $department?->id,
        ]);
    }

    private function createTask(User $creator, Department $department, string $title): Task
    {
        $task = new Task;
        $task->title = $title;
        $task->description = 'Task description.';
        $task->created_by = $creator->id;
        $task->department_id = $department->id;
        $task->save();

        return $task;
    }

    private function assignTask(Task $task, User $user): void
    {
        DB::table('task_assignments')->insert([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'is_responsible' => false,
        ]);
    }
}
