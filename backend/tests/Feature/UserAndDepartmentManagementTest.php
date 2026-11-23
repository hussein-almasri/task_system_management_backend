<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAndDepartmentManagementTest extends TestCase
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

        Route::middleware(['auth:sanctum', 'role:admin'])
            ->get('/_test/role-protected', fn () => response()->json(['authorized' => true]));
    }

    public function test_admin_can_list_all_users_without_sensitive_fields(): void
    {
        $department = $this->createDepartment('Engineering');
        $this->createUser('admin', null, 'Admin User', 'admin@example.test');
        $employee = $this->createUser('employee', $department, 'Employee User', 'employee@example.test');
        $this->actingAsRole('admin');

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');

        $this->getJson('/api/users/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.email', 'employee@example.test')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_admin_can_create_a_user_with_a_hashed_password(): void
    {
        $department = $this->createDepartment('Engineering');
        $role = $this->createRole('employee');
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'New Employee',
            'email' => 'new.employee@example.test',
            'password' => 'correct-horse-battery',
            'role_id' => $role->id,
            'department_id' => $department->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Employee')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user = User::query()->where('email', 'new.employee@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    public function test_admin_cannot_create_a_manager_or_employee_without_a_department(): void
    {
        $role = $this->createRole('employee');
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'Unassigned Employee',
            'email' => 'unassigned@example.test',
            'password' => 'correct-horse-battery',
            'role_id' => $role->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_passwords_longer_than_128_characters_are_rejected_on_create_and_update(): void
    {
        $department = $this->createDepartment('Engineering');
        $role = $this->createRole('employee');
        $user = $this->createUser('employee', $department, 'Existing Employee', 'existing@example.test');
        $this->actingAsRole('admin');
        $longPassword = str_repeat('p', 129);

        $this->postJson('/api/users', [
            'name' => 'New Employee',
            'email' => 'new.employee@example.test',
            'password' => $longPassword,
            'role_id' => $role->id,
            'department_id' => $department->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->putJson('/api/users/'.$user->id, ['password' => $longPassword])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_admin_can_update_a_user(): void
    {
        $department = $this->createDepartment('Engineering');
        $user = $this->createUser('employee', $department, 'Old Name', 'old@example.test');
        $this->actingAsRole('admin');

        $this->putJson('/api/users/'.$user->id, [
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
            'password' => 'new-secure-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user->refresh();
        $this->assertSame('updated@example.test', $user->email);
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_admin_can_activate_and_deactivate_a_user(): void
    {
        $department = $this->createDepartment('Engineering');
        $user = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        $this->actingAsRole('admin');

        $this->patchJson('/api/users/'.$user->id.'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->patchJson('/api/users/'.$user->id.'/status', ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_deactivating_a_user_revokes_all_of_their_tokens(): void
    {
        $department = $this->createDepartment('Engineering');
        $user = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        $user->createToken('phone');
        $user->createToken('tablet');
        $this->actingAsRole('admin');

        $this->patchJson('/api/users/'.$user->id.'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_activating_a_user_does_not_revoke_their_tokens(): void
    {
        $department = $this->createDepartment('Engineering');
        $user = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        $user->status = 'inactive';
        $user->save();
        $user->createToken('phone');
        $this->actingAsRole('admin');

        $this->patchJson('/api/users/'.$user->id.'/status', ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_inactive_users_with_existing_tokens_cannot_access_protected_routes(): void
    {
        $department = $this->createDepartment('Engineering');
        $user = $this->createUser('admin', $department, 'Admin User', 'admin@example.test');
        $token = $user->createToken('existing-device')->plainTextToken;
        $user->status = 'inactive';
        $user->save();

        $this->withToken($token)
            ->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');

        $this->withToken($token)
            ->getJson('/_test/role-protected')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');
    }

    public function test_admin_can_list_create_view_and_update_departments(): void
    {
        $this->createDepartment('Engineering');
        $this->actingAsRole('admin');

        $this->getJson('/api/departments')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $response = $this->postJson('/api/departments', [
            'name' => 'Operations',
            'description' => 'Company operations',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Operations');

        $departmentId = $response->json('data.id');

        $this->getJson('/api/departments/'.$departmentId)
            ->assertOk()
            ->assertJsonPath('data.description', 'Company operations');

        $this->putJson('/api/departments/'.$departmentId, [
            'name' => 'Business Operations',
            'description' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Business Operations')
            ->assertJsonPath('data.description', null);
    }

    public function test_admin_can_list_roles(): void
    {
        $this->createRole('admin');
        $this->createRole('employee');
        $this->createRole('department_manager');
        $this->actingAsRole('admin');

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment([
                'name' => 'department_manager',
                'description' => null,
            ]);
    }

    public function test_department_manager_can_only_list_and_view_employees_in_their_department(): void
    {
        $ownDepartment = $this->createDepartment('Engineering');
        $otherDepartment = $this->createDepartment('Finance');
        $manager = $this->createUser('department_manager', $ownDepartment, 'Manager', 'manager@example.test');
        $ownEmployee = $this->createUser('employee', $ownDepartment, 'Own Employee', 'own@example.test');
        $secondManager = $this->createUser('department_manager', $ownDepartment, 'Second Manager', 'manager2@example.test');
        $otherEmployee = $this->createUser('employee', $otherDepartment, 'Other Employee', 'other@example.test');
        Sanctum::actingAs($manager->load('role'));

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownEmployee->id);

        $this->getJson('/api/users/'.$ownEmployee->id)
            ->assertOk()
            ->assertJsonPath('data.id', $ownEmployee->id);

        $this->getJson('/api/users/'.$otherEmployee->id)->assertNotFound();
        $this->getJson('/api/users/'.$secondManager->id)->assertNotFound();
    }

    public function test_department_manager_cannot_manage_users_or_departments(): void
    {
        $department = $this->createDepartment('Engineering');
        $manager = $this->createUser('department_manager', $department, 'Manager', 'manager@example.test');
        $employee = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        Sanctum::actingAs($manager->load('role'));

        $this->postJson('/api/users', [])->assertForbidden();
        $this->putJson('/api/users/'.$employee->id, [])->assertForbidden();
        $this->patchJson('/api/users/'.$employee->id.'/status', ['status' => 'inactive'])->assertForbidden();
        $this->getJson('/api/departments')->assertForbidden();
        $this->postJson('/api/departments', [])->assertForbidden();
        $this->getJson('/api/departments/'.$department->id)->assertForbidden();
        $this->putJson('/api/departments/'.$department->id, [])->assertForbidden();
    }

    public function test_employee_cannot_access_user_or_department_management_endpoints(): void
    {
        $department = $this->createDepartment('Engineering');
        $employee = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        Sanctum::actingAs($employee->load('role'));

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/departments')->assertForbidden();
        $this->postJson('/api/users', [])->assertForbidden();
    }

    public function test_department_manager_cannot_list_roles(): void
    {
        $department = $this->createDepartment('Engineering');
        $manager = $this->createUser('department_manager', $department, 'Manager', 'manager@example.test');
        Sanctum::actingAs($manager->load('role'));

        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_employee_cannot_list_roles(): void
    {
        $department = $this->createDepartment('Engineering');
        $employee = $this->createUser('employee', $department, 'Employee', 'employee@example.test');
        Sanctum::actingAs($employee->load('role'));

        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_management_endpoints_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        $this->getJson('/api/departments')->assertUnauthorized();
        $this->postJson('/api/users', [])->assertUnauthorized();
    }

    private function createRole(string $name): Role
    {
        return Role::query()->create(['name' => $name]);
    }

    private function createDepartment(string $name): Department
    {
        return Department::query()->create(['name' => $name]);
    }

    private function createUser(
        string $roleName,
        ?Department $department,
        string $name,
        string $email,
    ): User {
        $role = Role::query()->where('name', $roleName)->first()
            ?? $this->createRole($roleName);

        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('fixture-password'),
            'status' => 'active',
            'role_id' => $role->id,
            'department_id' => $department?->id,
        ]);
    }

    private function actingAsRole(string $roleName): User
    {
        $user = $this->createUser(
            $roleName,
            null,
            'Administrator',
            'actor-'.$roleName.'@example.test',
        );
        Sanctum::actingAs($user->load('role'));

        return $user;
    }
}
