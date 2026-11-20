<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    #[Test]
    public function registration_creates_a_requester_and_returns_a_token(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'New@Example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'requester')
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'role']]);
    }

    #[Test]
    public function nobody_can_register_as_a_technician(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => 'technician',
        ])->assertCreated()->assertJsonPath('user.role', 'requester');

        $this->assertSame(Role::Requester, User::where('email', 'sneaky@example.com')->first()->role);
    }

    #[Test]
    public function login_returns_a_working_token(): void
    {
        $user = $this->requester();

        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    #[Test]
    public function wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $user = $this->requester();

        $wrong = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'nope'])->assertStatus(422);
        $unknown = $this->postJson('/api/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope'])->assertStatus(422);

        $this->assertSame($wrong->json('errors'), $unknown->json('errors'));
    }

    #[Test]
    public function logout_revokes_the_current_token(): void
    {
        $user = $this->requester();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    #[Test]
    public function protected_endpoints_require_a_token(): void
    {
        $this->getJson('/api/tickets')->assertUnauthorized();
        $this->postJson('/api/tickets', [])->assertUnauthorized();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        foreach (range(1, 10) as $_) {
            $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'x'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'x'])->assertStatus(429);
    }
}
