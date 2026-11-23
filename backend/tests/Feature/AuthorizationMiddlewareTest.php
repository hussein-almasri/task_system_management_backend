<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'role:admin'])
            ->get('/_test/authorization', fn () => response()->json(['authorized' => true]));
    }

    public function test_a_sanctum_authenticated_user_with_the_required_role_is_authorized(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->getJson('/_test/authorization')
            ->assertOk()
            ->assertExactJson(['authorized' => true]);
    }

    public function test_a_sanctum_authenticated_user_without_the_required_role_is_forbidden(): void
    {
        Sanctum::actingAs($this->userWithRole('employee'));

        $this->getJson('/_test/authorization')
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to access this resource.');
    }

    public function test_an_unauthenticated_user_cannot_reach_a_role_protected_route(): void
    {
        $this->getJson('/_test/authorization')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    private function userWithRole(string $roleName): User
    {
        $user = new User;
        $user->setRelation('role', new Role(['name' => $roleName]));

        return $user;
    }
}
