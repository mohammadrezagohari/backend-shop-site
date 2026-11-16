<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_and_access_identity(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'کاربر تازه',
            'email' => 'customer@example.test',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'customer@example.test')
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'کاربر تازه');
    }

    public function test_admin_routes_require_an_admin_bearer_token(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        $customer = User::factory()->create(['password' => 'password', 'is_admin' => false]);
        $customerToken = $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertOk()->json('data.token');
        $this->withToken($customerToken)->getJson('/api/v1/admin/dashboard')->assertForbidden();

        $admin = User::factory()->create(['password' => 'password', 'is_admin' => true]);
        $adminToken = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->json('data.token');
        $this->withToken($adminToken)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->withToken($adminToken)->getJson('/api/v1/auth/me')->assertJsonPath('data.id', $admin->id);
        $this->withToken($adminToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($adminToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
